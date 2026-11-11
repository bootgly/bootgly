<?php

use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Transaction;
use Bootgly\ADI\Databases\SQL\Transaction\Events;


return new Test(
   description: 'SQL::transact(): commits what the work returns, rolls back what it throws and nests as a savepoint (SQLite)',
   skip: extension_loaded('sqlite3') === false,
   test: function () {
      $file = sys_get_temp_dir() . '/bootgly-sqlite-transact-' . uniqid() . '.db';
      // ! A file database keeps the pool it is given: with two connections a
      //   second, independent transaction would be served, not refused — so a
      //   nested call that opened one would show as `created === 2`.
      $open = static fn (int $max = 2): SQL => new SQL([
         'driver' => 'sqlite',
         'database' => $file,
         'timeout' => 1.0,
         'pool' => ['min' => 0, 'max' => $max],
      ]);
      $census = static function (SQL $Database): array {
         $Pool = $Database->Pool;
         $locked = new ReflectionProperty($Pool, 'locked')->getValue($Pool);
         $open = new ReflectionProperty(SQL::class, 'Transactions')->getValue($Database);

         return [count($locked), count($Pool->busy), count($Pool->idle), count($Pool->pending), count($open)];
      };
      $tags = static function (SQL $Database): array {
         $Result = $Database->await($Database->query('SELECT tag FROM marks ORDER BY id'))->Result;
         $found = [];

         foreach ($Result->rows ?? [] as $row) {
            $found[] = $row['tag'];
         }

         return $found;
      };
      $mark = static fn (Transaction $Transaction, string $tag): mixed => $Transaction->await(
         $Transaction->query('INSERT INTO marks (tag) VALUES (?)', [$tag])
      );
      $listen = static function () use (&$heard): void {
         $heard = [];
         Emitter::$Instance = new Emitter;

         foreach ([Events::Begin, Events::Commit, Events::Rollback] as $Event) {
            Emitter::$Instance->listen($Event, static function (Emission $Emission) use (&$heard, $Event): void {
               /** @var Transaction $Transaction */
               $Transaction = $Emission->payload[0];
               $heard[] = "{$Event->name}@{$Transaction->depth}";
            });
         }
      };
      $heard = [];

      try {
         $Database = $open();
         $Database->await($Database->query('CREATE TABLE marks (id INTEGER PRIMARY KEY, tag TEXT)'));

         // # Commit + result passthrough
         $listen();
         $Seen = null;
         $result = $Database->transact(static function (Transaction $Transaction) use ($mark, &$Seen): array {
            $Seen = $Transaction;
            $mark($Transaction, 'committed');

            return ['id' => 7];
         });

         yield assert(
            assertion: $result === ['id' => 7]
               && $tags($Database) === ['committed']
               && $Seen instanceof Transaction && $Seen->depth === 0
               && $census($Database) === [0, 0, 1, 0, 0],
            description: 'The work\'s result is returned, its write is committed and the connection is back in the pool, found: '
               . json_encode([$result, $tags($Database), $Seen?->depth, $census($Database)])
         );

         yield assert(
            assertion: $heard === ['Commit@1'],
            description: 'The transaction it opens emits no Begin and one Commit, found: ' . json_encode($heard)
         );

         // # Rollback on throw — the same throwable comes back
         $listen();
         $Failure = new LogicException('the work failed');
         $Caught = null;

         try {
            $Database->transact(static function (Transaction $Transaction) use ($mark, $Failure): void {
               $mark($Transaction, 'rolled back');

               throw $Failure;
            });
         }
         catch (Throwable $Throwable) {
            $Caught = $Throwable;
         }

         yield assert(
            assertion: $Caught === $Failure
               && $tags($Database) === ['committed']
               && $census($Database) === [0, 0, 1, 0, 0]
               && $heard === ['Rollback@1'],
            description: 'A throwing work is rolled back, its throwable rethrown and the connection released, found: '
               . json_encode([$Caught?->getMessage(), $tags($Database), $census($Database), $heard])
         );

         // # A level the work left open is committed with the whole transaction
         $listen();
         $Database->transact(static function (Transaction $Transaction) use ($mark): void {
            $Transaction->await($Transaction->begin());
            $mark($Transaction, 'left open');
         });

         yield assert(
            assertion: $tags($Database) === ['committed', 'left open']
               && $census($Database) === [0, 0, 1, 0, 0]
               && $heard === ['Begin@1', 'Commit@2', 'Commit@1'],
            description: 'The unit of work is the whole transaction, not the level the work stopped on, found: '
               . json_encode([$tags($Database), $census($Database), $heard])
         );

         // # Nesting — a savepoint on the same transaction, never a second one
         $listen();
         $created = $Database->Pool->created;
         $Outer = null;
         $Inner = null;
         $depths = [];
         $Nested = new RuntimeException('the nested work failed');
         $Escaped = null;

         $Database->transact(static function (Transaction $Transaction) use ($Database, $mark, $Nested, &$Outer, &$Inner, &$depths, &$Escaped): void {
            $Outer = $Transaction;
            $mark($Transaction, 'outer');

            try {
               $Database->transact(static function (Transaction $Transaction) use ($mark, $Nested, &$Inner, &$depths): void {
                  $Inner = $Transaction;
                  $depths[] = $Transaction->depth;
                  $mark($Transaction, 'nested, rolled back');

                  throw $Nested;
               });
            }
            catch (Throwable $Throwable) {
               $Escaped = $Throwable;
            }

            $depths[] = $Transaction->depth;

            $Database->transact(static function (Transaction $Transaction) use ($mark, &$depths): void {
               $depths[] = $Transaction->depth;
               $mark($Transaction, 'nested, committed');
            });

            $depths[] = $Transaction->depth;
         });

         yield assert(
            assertion: $Outer !== null && $Inner === $Outer
               && $depths === [2, 1, 2, 1]
               && $Database->Pool->created === $created,
            description: 'A nested call runs on the transaction already open, one level deeper, on the same connection, found: '
               . json_encode([$Inner === $Outer, $depths, $Database->Pool->created, $created])
         );

         yield assert(
            assertion: $Escaped === $Nested
               && $tags($Database) === ['committed', 'left open', 'outer', 'nested, committed']
               && $census($Database) === [0, 0, 1, 0, 0],
            description: 'A nested failure rolls back its own level alone and reaches the outer work, found: '
               . json_encode([$Escaped?->getMessage(), $tags($Database), $census($Database)])
         );

         yield assert(
            assertion: $heard === ['Begin@1', 'Rollback@2', 'Begin@1', 'Commit@2', 'Commit@1'],
            description: 'A savepoint level emits Begin, and Rollback or Commit for the level it ends, found: ' . json_encode($heard)
         );

         // # A nested work that rolled its own level back and returned: there
         //   is nothing left of that call to commit — one more commit() would
         //   end the CALLER's transaction from inside the nested call.
         $depths = [];

         $Database->transact(static function (Transaction $Transaction) use ($Database, $mark, &$depths): void {
            $Database->transact(static function (Transaction $Transaction) use ($mark): void {
               $mark($Transaction, 'undone by the nested work');
               $Transaction->await($Transaction->rollback());
            });

            $depths[] = $Transaction->depth;
            $mark($Transaction, 'after the nested call');
         });

         yield assert(
            assertion: $depths === [1]
               && $tags($Database) === ['committed', 'left open', 'outer', 'nested, committed', 'after the nested call']
               && $census($Database) === [0, 0, 1, 0, 0],
            description: 'A nested call that already unwound its level leaves the outer transaction open for its caller, found: '
               . json_encode([$depths, $tags($Database), $census($Database)])
         );

         // # Another context never nests on this one's transaction
         $Parked = null;
         $Fiber = new Fiber(static function () use ($Database, $mark, &$Parked): void {
            $Database->transact(static function (Transaction $Transaction) use ($mark, &$Parked): void {
               $Parked = $Transaction;
               $mark($Transaction, 'fiber');

               Fiber::suspend();
            });
         });
         $Fiber->start();

         $Main = null;
         $Database->transact(static function (Transaction $Transaction) use (&$Main): void {
            $Main = $Transaction;
         });

         yield assert(
            assertion: $Parked instanceof Transaction && $Main instanceof Transaction
               && $Main !== $Parked && $Parked->depth === 1 && $Main->depth === 0
               && $Database->Pool->created === $created + 1,
            description: 'A call from another context opens its own transaction on its own connection, found: '
               . json_encode([$Main !== $Parked, $Parked?->depth, $Main?->depth, $Database->Pool->created])
         );

         $Fiber->resume();

         yield assert(
            assertion: $tags($Database) === ['committed', 'left open', 'outer', 'nested, committed', 'after the nested call', 'fiber']
               && $census($Database) === [0, 0, 2, 0, 0],
            description: 'The parked context commits its own unit of work when it resumes, found: '
               . json_encode([$tags($Database), $census($Database)])
         );

         // # The work ended the transaction itself — never a silent success
         $Caught = null;

         try {
            $Database->transact(static function (Transaction $Transaction) use ($mark): void {
               $mark($Transaction, 'ended by the work');
               $Transaction->await($Transaction->rollback());
            });
         }
         catch (Throwable $Throwable) {
            $Caught = $Throwable;
         }

         yield assert(
            assertion: $Caught?->getMessage() === 'SQL transaction is not active.'
               && in_array('ended by the work', $tags($Database), true) === false
               && $census($Database) === [0, 0, 2, 0, 0],
            description: 'A work that ends the top level itself is reported, not committed in silence, found: '
               . json_encode([$Caught?->getMessage(), $census($Database)])
         );

         // # A throwing Rollback listener: the work's failure survives and the
         //   transaction is ended anyway (the listener hears the level; the
         //   fallback teardown emits nothing).
         Emitter::$Instance = new Emitter;
         Emitter::$Instance->listen(Events::Rollback, static function (): void {
            throw new RuntimeException('a broken audit listener');
         });
         $Held = null;
         $Caught = null;

         try {
            $Database->transact(static function (Transaction $Transaction) use ($mark, $Failure, &$Held): void {
               $Held = $Transaction;
               $mark($Transaction, 'listener');

               throw $Failure;
            });
         }
         catch (Throwable $Throwable) {
            $Caught = $Throwable;
         }

         Emitter::$Instance = new Emitter;

         yield assert(
            assertion: $Caught === $Failure
               && $Held instanceof Transaction && $Held->depth === 0
               && in_array('listener', $tags($Database), true) === false
               && $census($Database) === [0, 0, 2, 0, 0],
            description: 'A Rollback listener that throws neither replaces the work\'s failure nor leaves the transaction open, found: '
               . json_encode([$Caught?->getMessage(), $Held?->depth, $census($Database)])
         );

         // # A nested level that cannot be rolled back ends the whole
         //   transaction; the outer call then reports it instead of committing
         //   a transaction that is gone, and a further nested call is refused
         //   rather than opening a new one.
         Emitter::$Instance->listen(Events::Rollback, static function (Emission $Emission): void {
            /** @var Transaction $Transaction */
            $Transaction = $Emission->payload[0];

            if ($Transaction->depth > 1) {
               throw new RuntimeException('a broken audit listener');
            }
         });
         $Caught = null;
         $refused = null;

         try {
            $Database->transact(static function (Transaction $Transaction) use ($Database, $mark, $Failure, &$refused): void {
               $mark($Transaction, 'outer of a stuck level');

               try {
                  $Database->transact(static function (Transaction $Transaction) use ($Failure): void {
                     throw $Failure;
                  });
               }
               catch (LogicException) {
                  // ? The outer work carries on, as if the level had been undone.
               }

               try {
                  $Database->transact(static fn (): bool => true);
               }
               catch (Throwable $Throwable) {
                  $refused = $Throwable->getMessage();
               }
            });
         }
         catch (Throwable $Throwable) {
            $Caught = $Throwable;
         }

         Emitter::$Instance = new Emitter;

         yield assert(
            assertion: $refused === 'SQL transaction is not active.'
               && $Caught?->getMessage() === 'SQL transaction is not active.'
               && in_array('outer of a stuck level', $tags($Database), true) === false
               && $census($Database) === [0, 0, 2, 0, 0],
            description: 'A level that cannot be rolled back takes the whole transaction, loudly, found: '
               . json_encode([$refused, $Caught?->getMessage(), $census($Database)])
         );

         // # Pool of one: the next statement succeeds after every way out
         $Single = $open(1);
         $outcomes = [];

         $Single->transact(static fn (Transaction $Transaction): mixed => $mark($Transaction, 'single'));
         $outcomes[] = $Single->await($Single->query('SELECT 1 AS n'))->Result?->cell;

         try {
            $Single->transact(static function () use ($Failure): void {
               throw $Failure;
            });
         }
         catch (LogicException) {
            $outcomes[] = $Single->await($Single->query('SELECT 2 AS n'))->Result?->cell;
         }

         try {
            $Single->transact(static function (Transaction $Transaction) use ($Failure): void {
               $Transaction->await($Transaction->begin());
               $Transaction->await($Transaction->begin());

               throw $Failure;
            });
         }
         catch (LogicException) {
            $outcomes[] = $Single->await($Single->query('SELECT 3 AS n'))->Result?->cell;
         }

         yield assert(
            assertion: $outcomes === [1, 2, 3] && $census($Single) === [0, 0, 1, 0, 0],
            description: 'On a pool of one connection the statement after transact() is served on every path, found: '
               . json_encode([$outcomes, $census($Single)])
         );

         // # A COMMIT the engine refuses is thrown, and nothing stays open
         $Single->await($Single->query('PRAGMA foreign_keys = ON'));
         $Single->await($Single->query('CREATE TABLE parents (id INTEGER PRIMARY KEY)'));
         $Single->await($Single->query(
            'CREATE TABLE children (id INTEGER PRIMARY KEY, parent INTEGER REFERENCES parents (id) DEFERRABLE INITIALLY DEFERRED)'
         ));
         $Caught = null;

         try {
            $Single->transact(static function (Transaction $Transaction): void {
               $Transaction->await($Transaction->query('INSERT INTO children (parent) VALUES (99)'));
            });
         }
         catch (Throwable $Throwable) {
            $Caught = $Throwable;
         }

         $children = $Single->await($Single->query('SELECT count(*) AS n FROM children'))->Result?->cell;

         yield assert(
            assertion: $Caught instanceof RuntimeException
               && str_contains($Caught->getMessage(), 'FOREIGN KEY')
               && $children === 0
               && $census($Single) === [0, 0, 1, 0, 0],
            description: 'A failed COMMIT is rethrown, never a silent success, and the connection is not left inside a transaction, found: '
               . json_encode([$Caught?->getMessage(), $children, $census($Single)])
         );
      }
      finally {
         Emitter::$Instance = new Emitter;

         foreach ([$file, "{$file}-journal", "{$file}-wal", "{$file}-shm"] as $path) {
            if (file_exists($path)) {
               unlink($path);
            }
         }
      }
   }
);
