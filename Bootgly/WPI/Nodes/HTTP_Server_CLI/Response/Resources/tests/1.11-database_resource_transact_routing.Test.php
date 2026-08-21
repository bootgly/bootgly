<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Tests\Routing;


use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function assert;
use function count;
use function fclose;
use function fread;
use function fwrite;
use function json_encode;
use function pack;
use function str_contains;
use function stream_set_blocking;
use function stream_socket_pair;
use function strlen;
use function strpos;
use function substr_count;
use Fiber;
use RuntimeException;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Model\Column;
use Bootgly\ADI\Databases\SQL\Model\Key;
use Bootgly\ADI\Databases\SQL\Model\Table;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Database;


#[Table('routed_rows')]
final class RoutedRow
{
   #[Key]
   public null|int $id = null;
   #[Column]
   public string $v = '';
}


return new Test(
   description: 'Resources: a query issued inside transact() runs inside that transaction',
   test: function () {
      $complete = static function (string $command): string {
         $command = "{$command}\0";

         return 'C' . pack('N', strlen($command) + 4) . $command . 'Z' . pack('N', 5) . 'I';
      };

      /**
       * Runs one shape over a socketpair backend on a one-connection pool.
       *
       * The pool holds exactly one connection, so a facade query issued while a
       * transaction pins it has nowhere else to go: before the routing it parked
       * and the deadline was the only way out. The timeout is short on purpose —
       * a revert-check should fail in two seconds, not in thirty.
       *
       * @return array{string, array<string,int>, null|string, mixed}
       */
      $rig = static function (callable $work) use ($complete): array {
         [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($server, false);

         $SQL = new SQL(['timeout' => 2.0, 'pool' => ['min' => 0, 'max' => 1]]);
         $SQL->Connection->attach($client);
         $Pool = $SQL->Pool;
         $Resource = new Database($SQL);

         // ! The scheduler bridge answers whatever the driver just wrote.
         $wire = '';
         $Resource->schedule(function (mixed $value = null) use ($server, &$wire, $complete): void {
            $wire .= (string) fread($server, 8192);

            fwrite($server, $complete('OK'));
         });

         $probe = null;
         $caught = null;

         try {
            $probe = $work($Resource, $SQL);
         }
         catch (Throwable $Throwable) {
            $caught = $Throwable->getMessage();
         }

         $wire .= (string) fread($server, 8192);

         $census = [
            'busy' => count($Pool->busy),
            'idle' => count($Pool->idle),
            'pending' => count($Pool->pending),
         ];

         fclose($server);
         $SQL->Connection->disconnect();

         return [$wire, $census, $caught, $probe];
      };

      // # A query issued through the resource inside transact() joins that transaction
      //   transact() hands the callback the resource itself, and the resource used to
      //   dispatch through the pool facade — asking for a connection OTHER than the one
      //   the transaction had pinned. With one connection there is no other, so the
      //   operation parked with no Connection, no Protocol and no Readiness, and await()
      //   waited on nothing until the deadline. The proof is not that it stops hanging:
      //   it is WHERE the statement appears, between this transaction's own BEGIN and
      //   COMMIT, on the connection the transaction holds.
      [$wire, $census, $caught] = $rig(
         static fn ($Resource) => $Resource->transact(
            static fn ($Transaction, $Db) => $Db->query("SELECT 'routed' AS v")
         )
      );

      $begin = strpos($wire, 'BEGIN');
      $select = strpos($wire, "SELECT 'routed' AS v");
      $commit = strpos($wire, 'COMMIT');

      yield assert(
         assertion: $caught === null
            && $begin !== false && $select !== false && $commit !== false
            && $begin < $select && $select < $commit,
         description: 'The facade query runs between this transaction BEGIN and COMMIT, found: '
            . json_encode(['error' => $caught, 'begin' => $begin, 'select' => $select, 'commit' => $commit])
      );

      yield assert(
         assertion: $census === ['busy' => 0, 'idle' => 1, 'pending' => 0],
         description: 'The pinned connection is handed back and nothing is left parked, found: '
            . json_encode($census)
      );

      // # The ORM lands in the same unit of work
      //   map() is the documented route shape, and Repository dispatches through the
      //   Querying surface it was built with. Inside the callback that surface must be
      //   the transaction; outside it must go back to being the pool facade — the second
      //   half is the control, and it fails if the surface is never restored.
      [, , $caught, $probe] = $rig(
         static function ($Resource, $SQL) {
            $inside = null;

            $Resource->transact(function ($Transaction, $Db) use (&$inside) {
               $inside = $Db->map(RoutedRow::class)->Querying === $Transaction;

               return null;
            });

            return [$inside, $Resource->map(RoutedRow::class)->Querying === $SQL];
         }
      );

      yield assert(
         assertion: $caught === null && $probe === [true, true],
         description: 'The ORM binds to the transaction inside and to the facade outside, found: '
            . json_encode(['error' => $caught, 'probe' => $probe])
      );

      // # A nested transact() is a savepoint on the surface already open
      //   Beginning again on the facade would open a SECOND transaction: on this pool it
      //   parks behind the connection the outer one holds, and on a pool with capacity it
      //   commits independently — writes the outer rollback can no longer reach. One
      //   BEGIN is the assertion that carries that.
      [$wire, $census, $caught] = $rig(
         static fn ($Resource) => $Resource->transact(
            static fn ($Transaction, $Db) => $Db->transact(
               static fn ($Inner, $Db2) => $Db2->query("SELECT 'nested' AS v")
            )
         )
      );

      yield assert(
         assertion: $caught === null
            && substr_count($wire, 'BEGIN') === 1
            && str_contains($wire, 'SAVEPOINT ')
            && str_contains($wire, 'RELEASE SAVEPOINT ')
            && substr_count($wire, 'COMMIT') === 1
            && $census === ['busy' => 0, 'idle' => 1, 'pending' => 0],
         description: 'A nested transact() opens a savepoint, not a second transaction, found: '
            . json_encode(['error' => $caught, 'census' => $census, 'wire' => $wire])
      );

      // # …and the outer rollback still reaches what the inner one wrote
      //   The inner unit of work returns normally and the OUTER one then fails. Releasing
      //   a savepoint is not committing it: the write stays inside the one transaction
      //   that ever began, and the single ROLLBACK below discards it. An independent
      //   transaction would instead have COMMITted here, out of the outer one's reach —
      //   which is what the second BEGIN used to buy, and why one BEGIN is the assertion.
      $Failure = new RuntimeException('outer work failed after the inner unit returned');

      [$wire, $census, $caught] = $rig(
         static function ($Resource) use ($Failure) {
            return $Resource->transact(static function ($Transaction, $Db) use ($Failure) {
               $Db->transact(static fn ($Inner, $Db2) => $Db2->query("INSERT INTO t (v) VALUES ('inner')"));

               throw $Failure;
            });
         }
      );

      $release = strpos($wire, 'RELEASE SAVEPOINT ');
      $rollback = strpos($wire, "ROLLBACK\0");

      yield assert(
         assertion: $caught === $Failure->getMessage()
            && substr_count($wire, 'BEGIN') === 1
            && str_contains($wire, 'COMMIT') === false
            && substr_count($wire, "ROLLBACK\0") === 1
            && $release !== false && $rollback !== false && $release < $rollback
            && $census === ['busy' => 0, 'idle' => 1, 'pending' => 0],
         description: 'The outer failure unwinds the nested level and then the transaction, found: '
            . json_encode(['error' => $caught, 'census' => $census, 'wire' => $wire])
      );

      // # The surface is given back even when the work throws
      //   transact() restores what it found rather than clearing, so a throwing callback
      //   cannot leave this resource dispatching into a transaction that no longer
      //   exists — every later query on the response would be refused by it.
      [$wire, , $caught, $probe] = $rig(
         static function ($Resource, $SQL) {
            $thrown = null;

            try {
               $Resource->transact(static function (): void {
                  throw new RuntimeException('work failed');
               });
            }
            catch (Throwable $Throwable) {
               $thrown = $Throwable->getMessage();
            }

            $Resource->query("SELECT 'after' AS v");

            return [$thrown, $Resource->map(RoutedRow::class)->Querying === $SQL];
         }
      );

      yield assert(
         assertion: $caught === null
            && $probe === ['work failed', true]
            && str_contains($wire, "SELECT 'after' AS v")
            && substr_count($wire, 'BEGIN') === 1,
         description: 'A throwing callback restores the surface and the next query is not wrapped, found: '
            . json_encode(['error' => $caught, 'probe' => $probe, 'wire' => $wire])
      );

      // # A nested call gives back its own level and no more
      //   The unwind loops are `do`-whiles, so their body runs once even when the
      //   callback already unwound past the depth the call found. At depth === entry
      //   === 1 that body is not a savepoint release — commit() takes its release
      //   branch only above depth 1 — so the nested call would end the CALLER's
      //   transaction from the inside, and the caller's own rollback would then have
      //   nothing left to reach. Depth 1 after the inner call is the assertion.
      [$wire, $census, $caught, $probe] = $rig(
         static function ($Resource) {
            $depth = null;

            $Resource->transact(static function ($Transaction, $Db) use ($Resource, &$depth) {
               $Db->transact(static function ($Inner) use ($Resource) {
                  // ! The inner unit of work abandons the level it opened
                  $Resource->await($Inner->rollback());

                  return null;
               });

               $depth = $Transaction->depth;

               return null;
            });

            return $depth;
         }
      );

      yield assert(
         assertion: $caught === null
            && $probe === 1
            && substr_count($wire, 'BEGIN') === 1
            && substr_count($wire, 'COMMIT') === 1
            && $census === ['busy' => 0, 'idle' => 1, 'pending' => 0],
         description: 'A nested call that unwinds itself leaves the caller transaction open, found: '
            . json_encode(['error' => $caught, 'depth' => $probe, 'census' => $census, 'wire' => $wire])
      );

      // # An inner call hands the outer surface back, not a cleared one
      //   The restore saves and re-installs what it found. Clearing instead would
      //   look right for a top-level call and put the OUTER callback back on the
      //   pool for the rest of its body — this entry's own defect, one level in.
      [$wire, , $caught, $probe] = $rig(
         static function ($Resource) {
            $bound = null;

            $Resource->transact(static function ($Transaction, $Db) use (&$bound) {
               $Db->transact(static fn (): mixed => null);

               $bound = $Db->map(RoutedRow::class)->Querying === $Transaction;
               $Db->query("SELECT 'resumed' AS v");

               return null;
            });

            return $bound;
         }
      );

      $resumed = strpos($wire, "SELECT 'resumed' AS v");
      $commit = strpos($wire, 'COMMIT');

      yield assert(
         assertion: $caught === null
            && $probe === true
            && $resumed !== false && $commit !== false && $resumed < $commit,
         description: 'A returning inner transact() hands the outer surface back, found: '
            . json_encode(['error' => $caught, 'bound' => $probe, 'wire' => $wire])
      );

      // # …and `fetch()` is on the routed path too
      //   It reaches the surface only by delegating to query(). Nothing pinned that,
      //   so a fetch() wired straight to the facade would read outside the unit of
      //   work with the suite still green. The stub answers no rows, so what is
      //   asserted is where the statement went, not what came back.
      [$wire, , $caught] = $rig(
         static function ($Resource) {
            $Resource->transact(static function ($Transaction, $Db) {
               try {
                  $Db->fetch("SELECT 'fetched' AS v");
               }
               catch (Throwable) {
                  // ! The rig's backend returns no rows; the wire is the assertion.
               }

               return null;
            });

            return null;
         }
      );

      $begin = strpos($wire, 'BEGIN');
      $fetched = strpos($wire, "SELECT 'fetched' AS v");
      $commit = strpos($wire, 'COMMIT');

      yield assert(
         assertion: $caught === null
            && $begin !== false && $fetched !== false && $commit !== false
            && $begin < $fetched && $fetched < $commit,
         description: 'fetch() runs inside the transaction as well, found: '
            . json_encode(['error' => $caught, 'begin' => $begin, 'fetched' => $fetched, 'commit' => $commit])
      );

      // # A nested call gives back the levels IT opened, counted from what it found
      //   `$entry` is read before the nested begin. Reading it after would make the
      //   inner call return with a level of its own still open, which is the whole
      //   reason the depth is recorded rather than assumed to be zero.
      [, , $caught, $probe] = $rig(
         static function ($Resource) {
            $depth = null;

            $Resource->transact(static function ($Transaction, $Db) use ($Resource, &$depth) {
               $Db->transact(static function ($Inner) use ($Resource) {
                  // ! The inner unit of work leaves a level of its own open
                  $Resource->await($Inner->begin());

                  return null;
               });

               $depth = $Transaction->depth;

               return null;
            });

            return $depth;
         }
      );

      yield assert(
         assertion: $caught === null && $probe === 1,
         description: 'A nested call closes the levels it opened and no others, found: '
            . json_encode(['error' => $caught, 'depth' => $probe])
      );

      // # The surface belongs to the context that opened it
      //   One resource object is reachable from two execution contexts —
      //   Resources::fork() carries a definition-less mount into the clone by
      //   reference, and a plain capture crosses defer() with no registry at all. A
      //   surface without an owner hands a stranger's request the transaction: rows
      //   it may not read, and its own writes destroyed by a rollback it never asked
      //   for. Outside the Fiber that opened it, dispatch must fall back to the pool.
      [, , $caught, $probe] = $rig(
         static function ($Resource, $SQL) {
            $inside = null;

            $Fiber = new Fiber(static function () use ($Resource, &$inside): void {
               $Resource->transact(static function ($Transaction, $Db) use (&$inside) {
                  $inside = $Db->map(RoutedRow::class)->Querying === $Transaction;

                  Fiber::suspend();

                  return null;
               });
            });
            $Fiber->start();

            // ! A different context, the same resource object, no transaction of its own
            $outside = $Resource->map(RoutedRow::class)->Querying === $SQL;

            $Fiber->resume();

            return [$inside, $outside];
         }
      );

      yield assert(
         assertion: $caught === null && $probe === [true, true],
         description: 'A transaction is not shared with another execution context, found: '
            . json_encode(['error' => $caught, 'probe' => $probe])
      );
   }
);
