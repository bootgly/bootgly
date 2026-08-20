<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Driver;
use Bootgly\ADI\Database\Operation;
use Bootgly\ADI\Database\Pool;
use Bootgly\ADI\Databases\SQL;


return new Test(
   description: 'Database: a cancel reaches the wire only for work that is on it',
   test: function () {
      // ! A complete backend answer: CommandComplete then ReadyForQuery.
      $complete = static function (string $command): string {
         $command = "{$command}\0";

         return 'C' . pack('N', strlen($command) + 4) . $command . 'Z' . pack('N', 5) . 'I';
      };
      /**
       * Opens a pooled database over a socketpair, with the peer alongside.
       *
       * @return array{SQL, resource}
       */
      $open = static function (int $max): array {
         [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($server, false);

         $Database = new SQL(['timeout' => 30.0, 'pool' => ['min' => 0, 'max' => $max]]);
         $Database->Connection->attach($client);

         return [$Database, $server];
      };
      // ! A driver whose cancel genuinely reaches the server: the request goes
      //   out and the operation still waits for the answer it provokes. No
      //   in-tree driver can be driven to that state without a live server.
      $cancelling = static function (SQL $Database): Driver {
         return new class ($Database->Config, $Database->Connection) extends Driver {
            public function prepare (Operation $Operation): Operation
            {
               return $Operation;
            }

            public function advance (Operation $Operation): Operation
            {
               return $Operation;
            }

            public function cancel (Operation $Operation): Operation
            {
               $Operation->cancelled = true;

               return $Operation;
            }
         };
      };
      $busy = static fn (Pool $Pool): int => count($Pool->busy);
      $reserved = static fn (Pool $Pool): int => count(
         (new ReflectionProperty(Pool::class, 'locked'))->getValue($Pool)
      );

      // # An operation that has already left the wire
      //   A cancel request names a backend, not a statement. Sending one for
      //   work that is over reaches whatever holds the connection since — and
      //   the pool hands a finished operation's connection to the next caller.
      [$Database, $server] = $open(1);

      $Done = $Database->query('SELECT 1 AS v');
      $Database->advance($Done);
      fread($server, 8192);
      fwrite($server, $complete('SELECT 1'));
      $Database->advance($Done);

      $finished = $Done->finished && $Done->error === null;

      $Database->cancel($Done);

      yield assert(
         assertion: $finished
            && $Done->error === null
            && $Done->state->name === 'Finished'
            && $Done->revoked,
         description: 'Cancelling a finished operation never consults its driver'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # An operation that never reached the wire
      //   `Queued` is composed-but-unwritten in every driver, so the server has
      //   never heard of it. It is withdrawn locally, and the error says so
      //   rather than reporting whatever the driver would have refused with.
      [$Database, $server] = $open(1);
      $Pool = $Database->Pool;

      $Unwritten = $Database->query('INSERT INTO t (id) VALUES (1)');
      $Database->Pool->assign($Unwritten);

      $queued = $Unwritten->state->name;

      $Database->cancel($Unwritten);

      yield assert(
         assertion: $queued === 'Queued'
            && $Unwritten->finished
            && $Unwritten->error === 'Database operation was cancelled before reaching the server.'
            && $Pool->pending === [],
         description: 'A statement cancelled before it is written never reaches the driver'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # …and a withdrawn teardown does not hand the transaction away
      //   `commit()` and `rollback()` carry `unlock`, and release() honours it
      //   whatever the outcome — right for a teardown the wire refused, since
      //   that session is suspect and the connection goes anyway. A teardown
      //   that never reached the server ended nothing: the transaction is still
      //   open and still holds its locks, so the reservation has to stand.
      //   Releasing it lent an open transaction to the next caller, whose write
      //   then vanished with a session it never knew existed.
      [$Database, $server] = $open(1);
      $Pool = $Database->Pool;

      $Transaction = $Database->begin();
      $Begin = $Transaction->Operation;

      $Database->advance($Begin);
      fread($server, 8192);
      fwrite($server, $complete('BEGIN'));
      $Database->advance($Begin);

      $held = $reserved($Pool);
      $Commit = $Transaction->commit();
      $carried = $Commit->unlock;

      $Database->cancel($Commit);

      // @ And the caller then collects what it withdrew, which is what the
      //   manual tells it to do. Suppressing the release instead of the intent
      //   left the claim unsettled here, so this very call released it with the
      //   flag honoured after all — the fix deferred by exactly one advance.
      $Database->advance($Commit);

      yield assert(
         assertion: $held === 1
            && $carried
            && $Commit->finished
            && $reserved($Pool) === 1
            && $busy($Pool) === 1,
         description: 'A withdrawn transaction teardown keeps its reservation'
      );

      // # …and the intent survives, because one route still ends that transaction
      //   The caller can re-arm the teardown it withdrew — `retry()` says so in
      //   as many words — and that COMMIT does reach the server. Dropping the
      //   flag for good instead of suppressing it for one release left the slot
      //   reserved for a transaction that had closed, measured on two engines.
      $rearmed = $Commit->unlock;

      $Commit->retry($Database->Connection);

      // @ One turn to re-enter the queue, one to reach the wire.
      $Database->advance($Commit);
      $Database->advance($Commit);

      fread($server, 8192);
      fwrite($server, $complete('COMMIT'));
      $Database->advance($Commit);

      yield assert(
         assertion: $rearmed
            && $Commit->finished
            && $Commit->error === null
            && $reserved($Pool) === 0
            && $busy($Pool) === 0,
         description: 'A re-armed teardown that reaches the server frees the reservation'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # …and the deadline reaches the same conclusion as the cancel
      //   A teardown can also be retired by its own deadline, and that route
      //   settles the claim through the same call. It ended nothing either, so
      //   it must not hand the open transaction to whoever asks next — which is
      //   what it did while the rule lived only in cancel().
      [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($peer, false);

      $Database = new SQL(['timeout' => 0.05, 'pool' => ['min' => 0, 'max' => 1]]);
      $Database->Connection->attach($client);
      $Pool = $Database->Pool;

      $Transaction = $Database->begin();
      $Begin = $Transaction->Operation;

      $Database->advance($Begin);
      fread($peer, 8192);
      fwrite($peer, $complete('BEGIN'));
      $Database->advance($Begin);

      $opened = $reserved($Pool) === 1;
      $Expired = $Transaction->commit();

      usleep(80_000);
      $Database->advance($Expired);

      yield assert(
         assertion: $opened
            && $Expired->finished
            && $Expired->unlock
            && $reserved($Pool) === 1
            && $busy($Pool) === 1,
         description: 'A teardown retired by its deadline keeps its reservation too'
      );

      fclose($peer);
      $Database->Connection->disconnect();

      // # …unless the connection under it is gone
      //   Then there is no session left to protect and no transaction left to
      //   end, so the reservation dies with the connection. Reaching this needs
      //   the release to run at all, which is why the intent is dropped rather
      //   than the release skipped.
      [$Database, $server] = $open(1);
      $Pool = $Database->Pool;

      $Transaction = $Database->begin();
      $Begin = $Transaction->Operation;

      $Database->advance($Begin);
      fread($server, 8192);
      fwrite($server, $complete('BEGIN'));
      $Database->advance($Begin);

      $Commit = $Transaction->commit();

      fclose($server);
      $Database->Connection->disconnect();

      $Database->cancel($Commit);

      yield assert(
         assertion: $Commit->finished
            && $Pool->created === 0
            && $busy($Pool) === 0
            && $reserved($Pool) === 0,
         description: 'A reservation whose connection is gone dies with it'
      );

      // # A finished operation the pool still has parked leaves the queue
      //   `pending` carries live work only, and returning early for a finished
      //   operation must not make it the one exception.
      $Database = new SQL(['timeout' => 30.0, 'pool' => ['min' => 0, 'max' => 0]]);
      $Pool = $Database->Pool;

      $Parked = $Database->query('SELECT 1 AS v');
      $parked = count($Pool->pending);

      $Parked->fail('Finished by something other than this pool.');

      $Database->cancel($Parked);

      yield assert(
         assertion: $parked === 1 && $Pool->pending === [],
         description: 'Cancelling a finished operation still takes it out of pending'
      );

      // # A cancel that goes out onto a wire that can no longer answer
      //   The answer the cancel provokes is what normally finishes the
      //   operation and frees the slot. On a connection that can deliver
      //   nothing, nobody settles the claim and the pool counts it forever.
      [$Database, $server] = $open(1);
      $Pool = $Database->Pool;

      $Lost = $Database->query('SELECT 1 AS v');
      $Database->advance($Lost);
      fread($server, 8192);

      $Stub = $cancelling($Database);
      $Lost->Protocol = $Stub;
      $Database->Connection->bind($Stub);

      fclose($server);
      $Database->Connection->disconnect();

      $Database->cancel($Lost);

      yield assert(
         assertion: $Lost->cancelled
            && $Lost->finished
            && $Lost->error === 'Database connection was lost while cancelling the operation.'
            && $busy($Pool) === 0
            && $Pool->created === 0,
         description: 'A cancel on a connection that cannot answer settles the claim'
      );

      // # …and a usable connection is left exactly alone
      //   The answer is still coming on a live wire, so finishing the operation
      //   here would discard it. This is the shape the pool heals on its own,
      //   and it must stay untouched.
      [$Database, $server] = $open(1);
      $Pool = $Database->Pool;

      $Live = $Database->query('SELECT 1 AS v');
      $Database->advance($Live);
      fread($server, 8192);

      $Stub = $cancelling($Database);
      $Live->Protocol = $Stub;
      $Database->Connection->bind($Stub);

      $Database->cancel($Live);

      yield assert(
         assertion: $Live->cancelled
            && $Live->finished === false
            && $Live->error === null
            && $busy($Pool) === 1,
         description: 'A cancel on a live connection leaves the answer to arrive'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
