<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Driver;
use Bootgly\ADI\Database\Operation;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Database\Pool;
use Bootgly\ADI\Databases\KV;
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

      // # …and a withdrawn teardown ends the transaction nobody else can
      //   `commit()` and `rollback()` carry `unlock`, and release() honours it
      //   whatever the outcome — right for a teardown the wire refused, since
      //   that session is suspect and the connection goes anyway. One that never
      //   reached the server ended nothing, and by then nobody is left to end
      //   it: `Transaction` gave up its depth and its connection when it
      //   composed the statement. Releasing the reservation lent an open
      //   transaction to the next caller, whose write vanished with a session it
      //   never knew existed; keeping it stranded the slot for good and left
      //   that session open anyway. Dropping the connection rolls the
      //   transaction back server-side and gives the slot back.
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

      $Severed = $Commit->Protocol;

      $Database->cancel($Commit);

      $gone = is_resource($Database->Connection->socket) === false;

      // @ And the caller then collects what it withdrew, which is what the
      //   manual tells it to do. Settling it must be idempotent: an earlier
      //   shape left the claim unrecorded here, so this very call released the
      //   connection a second time.
      $Database->advance($Commit);

      yield assert(
         assertion: $held === 1
            && $carried
            && $gone
            && $Commit->finished
            && $reserved($Pool) === 0
            && $busy($Pool) === 0
            && $Pool->created === 0,
         description: 'A withdrawn teardown drops the connection it could not end'
      );

      // # …and what comes back is a new session, not the old one
      //   Asserting only that the pool serves again passes on a settle() that
      //   drops nothing at all — the slot was never taken in that case. What
      //   distinguishes the fix is that the driver behind the next operation is
      //   not the one that was severed.
      $Serving = $Database->query('SELECT 1 AS v');
      $Database->advance($Serving);

      yield assert(
         assertion: $Serving->state->name !== 'Pending'
            && $Serving->error === null
            && $Pool->created === 1
            && $Serving->Protocol !== null
            && $Serving->Protocol !== $Severed,
         description: 'The slot comes back on a session that is not the severed one'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # …and the deadline reaches the same conclusion as the cancel
      //   A teardown can also be retired by its own deadline, and that route
      //   settles the claim through the same call. It ended nothing either, and
      //   while the rule lived only in cancel() this route handed the open
      //   transaction to whoever asked next — reached by any application that
      //   merely sets a timeout, without calling cancel() at all.
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
            && $reserved($Pool) === 0
            && $busy($Pool) === 0
            && $Pool->created === 0,
         description: 'A teardown retired by its deadline is ended the same way'
      );

      fclose($peer);
      $Database->Connection->disconnect();

      // # A reused transaction waits for an unrelated reader to release capacity
      //   A completed transaction owns no session. Re-attaching it to the
      //   connection carried by its old COMMIT lets a new BEGIN reserve that
      //   connection while another caller is reading on it. The BEGIN must start
      //   unpinned and become reserved only after the reader returns the slot.
      [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($peer, false);

      $Database = new SQL(['timeout' => 30.0, 'pool' => ['min' => 0, 'max' => 1]]);
      $Database->Connection->attach($client);
      $Pool = $Database->Pool;

      $Transaction = $Database->begin();
      $Opened = $Transaction->Operation;

      $Database->advance($Opened);
      fread($peer, 8192);
      fwrite($peer, $complete('BEGIN'));
      $Database->advance($Opened);

      $Closed = $Transaction->commit();
      $Database->advance($Closed);
      fread($peer, 8192);
      fwrite($peer, $complete('COMMIT'));
      $Database->advance($Closed);

      // @ An unrelated caller takes the connection and is genuinely reading.
      $Reader = $Database->query('SELECT 1 AS v');
      $Database->advance($Reader);
      fread($peer, 8192);

      // @ Reuse asks for a new exclusive slot. With max=1 it waits in pending
      //   until the reader's response promotes and flushes the BEGIN.
      $Reused = $Transaction->begin();

      yield assert(
         assertion: $Reused->state->name === 'Pending'
            && $Reused->Connection === null
            && $reserved($Pool) === 0
            && $Reader->finished === false,
         description: 'A reused transaction never reserves the connection of an active reader'
      );

      fwrite($peer, $complete('SELECT 1'));
      $Database->advance($Reader);
      $wire = (string) fread($peer, 8192);

      yield assert(
         assertion: $Reader->finished
            && $Reused->state->name === 'Reading'
            && $Reused->Connection === $Reader->Connection
            && $reserved($Pool) === 1
            && str_contains($wire, "BEGIN\0"),
         description: 'Releasing the reader promotes BEGIN and only then reserves its connection'
      );

      fclose($peer);
      $Database->Connection->disconnect();

      // # A sibling on the severed session is failed, not abandoned
      //   Severing has to go through the driver: dropping the transport from
      //   outside leaves whoever else was on that session unfinished and
      //   errorless forever, and leaves the driver holding a pipeline for a
      //   session that no longer exists.
      [$Database, $server] = $open(1);
      $Pool = $Database->Pool;

      $Sibling = $Database->query('SELECT 1 AS v');
      $Database->advance($Sibling);
      fread($server, 8192);

      // ! A teardown pinned to the same connection, composed but never written.
      $Teardown = $Database->query('COMMIT');
      $Teardown->unlock = true;
      $Database->Pool->assign($Teardown);

      $shared = $Teardown->Connection === $Sibling->Connection;

      $Database->cancel($Teardown);

      yield assert(
         assertion: $shared
            && $Sibling->finished
            && $Sibling->error === 'Database transaction teardown never reached the server.',
         description: 'Severing a session fails the siblings it was carrying'
      );

      fclose($server);

      // # Every driver severs through its own teardown
      //   The pool reaches only one driver per configuration, so the other
      //   implementations of the contract go unexercised by the routes above —
      //   and an override that quietly does nothing would leave a whole engine
      //   dropping transports from outside again.
      $severing = static function (string $driver): array {
         [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($peer, false);

         // ! Redis is a key-value database, not an SQL one.
         $Database = $driver === 'redis'
            ? new KV(['driver' => 'redis', 'timeout' => 30.0, 'pool' => ['min' => 0, 'max' => 1]])
            : new SQL(['driver' => $driver, 'timeout' => 30.0, 'pool' => ['min' => 0, 'max' => 1]]);
         $Database->Connection->attach($client);

         $Operation = $driver === 'redis'
            ? $Database->command('GET', ['k'])
            : $Database->query('SELECT 1 AS v');
         $Database->advance($Operation);
         fread($peer, 8192);

         $Protocol = $Operation->Protocol;
         $Protocol?->sever($Operation, 'Severed by the pool.');

         $severed = [
            $Operation->finished,
            $Operation->error === 'Severed by the pool.',
            is_resource($Database->Connection->socket) === false,
         ];

         fclose($peer);

         return $severed;
      };

      yield assert(
         assertion: $severing('mysql') === [true, true, true]
            && $severing('redis') === [true, true, true],
         description: 'Every driver severs through its own teardown'
      );

      // # A retry keeps what decides who owns a connection
      //   `retry()` re-arms an operation for another attempt, and the two flags
      //   that say what it does to the pool's reservation are not part of the
      //   attempt. Clearing either turns a teardown into an ordinary statement.
      $Reserving = new SQL(['timeout' => 30.0, 'pool' => ['min' => 0, 'max' => 0]]);
      $Flagged = $Reserving->query('COMMIT');
      $Flagged->unlock = true;
      $Flagged->lock = true;
      $Flagged->retry();

      yield assert(
         assertion: $Flagged->unlock && $Flagged->lock,
         description: 'Retrying keeps the reservation flags'
      );

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

      // # A teardown the wire DID carry leaves its session alone
      //   Severing is for a statement the server never saw. A commit already on
      //   the wire is being answered right now, and the deadline that retires it
      //   here says nothing about the session — killing it would take the
      //   co-located reader down with a transaction that may well have
      //   committed. This is the shape the `sent` precondition exists for, and
      //   the only route that produces it is an expired teardown, never cancel().
      [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($peer, false);

      $Database = new SQL(['timeout' => 0.05, 'pool' => ['min' => 0, 'max' => 1]]);
      $Database->Connection->attach($client);
      $Pool = $Database->Pool;

      $Reader = $Database->query('SELECT 1 AS v');
      $Database->advance($Reader);
      fread($peer, 8192);

      $Teardown = $Database->query('COMMIT');
      $Teardown->unlock = true;
      $Database->advance($Teardown);
      fread($peer, 8192);

      $Connection = $Teardown->Connection;
      $Protocol = $Teardown->Protocol;
      $onTheWire = $Teardown->state !== OperationStates::Queued
         && $Connection === $Reader->Connection;

      usleep(80_000);
      $Database->advance($Teardown);

      yield assert(
         assertion: $onTheWire
            && $Teardown->finished
            && $Teardown->unlock
            && $Reader->finished === false
            && $Reader->error === null
            && $Connection?->Protocol === $Protocol
            && is_resource($Connection?->socket),
         description: 'A teardown already on the wire leaves its session alone'
      );

      fclose($peer);
      $Database->Connection->disconnect();
   }
);
