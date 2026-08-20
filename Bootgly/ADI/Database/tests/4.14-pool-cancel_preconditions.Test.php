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
