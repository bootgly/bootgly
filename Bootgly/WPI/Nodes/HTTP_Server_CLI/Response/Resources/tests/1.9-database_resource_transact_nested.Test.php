<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Tests;


use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function assert;
use function count;
use function fclose;
use function fread;
use function fwrite;
use function pack;
use function stream_set_blocking;
use function stream_socket_pair;
use function strlen;
use function substr_count;
use RuntimeException;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Database;


return new Test(
   description: 'Resources: transact() ends every level the work left open',
   test: function () {
      $complete = static function (string $command): string {
         $command = "{$command}\0";

         return 'C' . pack('N', strlen($command) + 4) . $command . 'Z' . pack('N', 5) . 'I';
      };

      /**
       * Runs one transact() shape over a socketpair backend.
       *
       * @return array{string, int, array<string,int>, bool, null|string}
       */
      $transact = static function (callable $work) use ($complete): array {
         [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($server, false);

         $SQL = new SQL(['timeout' => 30.0, 'pool' => ['min' => 0, 'max' => 1]]);
         $SQL->Connection->attach($client);
         $Pool = $SQL->Pool;
         $Resource = new Database($SQL);

         // ! The scheduler bridge answers whatever the driver just wrote.
         $wire = '';
         $Resource->schedule(function (mixed $value = null) use ($server, &$wire, $complete): void {
            $wire .= (string) fread($server, 8192);

            fwrite($server, $complete('OK'));
         });

         $depth = 0;
         $caught = null;

         try {
            $Resource->transact(function ($Transaction) use ($work, $Resource, &$depth) {
               $result = $work($Transaction, $Resource);
               $depth = $Transaction->depth;

               return $result;
            });
         }
         catch (Throwable $Throwable) {
            $caught = $Throwable->getMessage();
         }

         $wire .= (string) fread($server, 8192);

         $census = [
            'busy'    => count($Pool->busy),
            'idle'    => count($Pool->idle),
            'pending' => count($Pool->pending),
         ];

         $Next = $SQL->query('SELECT 1 AS v');
         $assigned = $Next->Connection !== null && $Pool->pending === [];

         fclose($server);
         $SQL->Connection->disconnect();

         return [$wire, $depth, $census, $assigned, $caught];
      };

      // # A — the work nests, then throws
      //   `begin()` inside the callback is a savepoint. One rollback() unwinds
      //   that savepoint and leaves the transaction open, so the outer ROLLBACK
      //   is never sent and the pinned connection is never handed back.
      $Failure = new RuntimeException('work failed');

      [$wire, , $census, $assigned, $caught] = $transact(
         static function ($Transaction, $Resource) use ($Failure) {
            $Resource->await($Transaction->begin());
            $Resource->await($Transaction->query("INSERT INTO t (v) VALUES ('nested')"));

            throw $Failure;
         }
      );

      yield assert(
         assertion: $caught === 'work failed'
            && substr_count($wire, "ROLLBACK TO SAVEPOINT ") === 1
            && substr_count($wire, "ROLLBACK\0") === 1,
         description: 'A failing nested work rolls back its savepoint and then the transaction'
      );

      yield assert(
         assertion: $census['busy'] === 0 && $census['idle'] === 1 && $assigned,
         description: 'The connection a failing nested transaction pinned is back in the pool'
      );

      // # B — the work nests and succeeds
      //   The worse half: commit() at depth > 1 releases a savepoint, so the
      //   caller was told its unit of work had committed while no COMMIT was
      //   ever sent for the transaction holding it.
      [$wire, $depth, $census, $assigned, $caught] = $transact(
         static function ($Transaction, $Resource) {
            $Resource->await($Transaction->begin());
            $Resource->await($Transaction->query("INSERT INTO t (v) VALUES ('nested')"));

            return 'done';
         }
      );

      yield assert(
         assertion: $caught === null
            && $depth === 2
            && substr_count($wire, "RELEASE SAVEPOINT ") === 1
            && substr_count($wire, "COMMIT\0") === 1,
         description: 'A succeeding nested work releases its savepoint and then commits'
      );

      yield assert(
         assertion: $census['busy'] === 0 && $census['idle'] === 1 && $assigned,
         description: 'The connection a succeeding nested transaction pinned is back in the pool'
      );

      // # C — a flat transaction is untouched
      [$wire, , $census, $assigned, $caught] = $transact(
         static function ($Transaction, $Resource) {
            $Resource->await($Transaction->query("INSERT INTO t (v) VALUES ('flat')"));

            return 'flat';
         }
      );

      yield assert(
         assertion: $caught === null
            && substr_count($wire, "COMMIT\0") === 1
            && substr_count($wire, 'SAVEPOINT') === 0
            && $census['busy'] === 0 && $assigned,
         description: 'A transaction the work never nested still sends exactly one COMMIT'
      );

      // # D — work that closes its own level is not torn down twice
      //   The unwind must be driven by the depth the work actually left, never
      //   by a fixed number of teardowns.
      [$wire, $depth, $census, $assigned, $caught] = $transact(
         static function ($Transaction, $Resource) {
            $Resource->await($Transaction->begin());
            $Resource->await($Transaction->commit());

            return 'balanced';
         }
      );

      yield assert(
         assertion: $caught === null
            && $depth === 1
            && substr_count($wire, "RELEASE SAVEPOINT ") === 1
            && substr_count($wire, "COMMIT\0") === 1
            && $census['busy'] === 0 && $assigned,
         description: 'Work that closed its own savepoint is committed once, not twice'
      );

      // # E — a nested teardown that cannot advance ends the unwind
      //   A savepoint rollback is an ordinary statement, so it is refused while
      //   the tracked operation is still in flight — and refusing does not
      //   change `depth`. Driving the unwind off `depth` alone would spin here
      //   forever. The connection stays pinned afterwards: that is `SQL-7`,
      //   which needs the teardown to bypass the savepoint guard entirely.
      $Failure = new RuntimeException('work failed with a write in flight');

      [, , , , $caught] = $transact(
         static function ($Transaction, $Resource) use ($Failure) {
            $Resource->await($Transaction->begin());

            // ! Issued and never awaited, so the transaction is not `ready()`.
            $Transaction->query("INSERT INTO t (v) VALUES ('orphan')");

            throw $Failure;
         }
      );

      yield assert(
         assertion: $caught === 'work failed with a write in flight',
         description: 'An unwind that cannot advance stops instead of spinning'
      );
   }
);
