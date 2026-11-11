<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ABI\Events\Emitter;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Transaction;
use Bootgly\ADI\Databases\SQL\Transaction\Events;


/**
 * The `SQL::transact()` scenario the live PostgreSQL and MySQL specs share.
 *
 * `$Open(int $max): SQL` opens a facade on the live server with a pool of
 * `$max` connections; `$driver` is `pgsql` or `mysql`.
 */
return static function (Closure $Open, string $driver): Generator {
   $table = 'bootgly_transact_' . uniqid();
   $census = static function (SQL $Database): array {
      $Pool = $Database->Pool;
      $locked = new ReflectionProperty($Pool, 'locked')->getValue($Pool);

      return [count($locked), count($Pool->busy), count($Pool->pending)];
   };
   $tags = static function (SQL $Database) use ($table): array {
      $Result = $Database->await($Database->query("SELECT tag FROM {$table} ORDER BY id"))->Result;
      $found = [];

      foreach ($Result->rows ?? [] as $row) {
         $found[] = $row['tag'];
      }

      return $found;
   };
   $mark = static fn (Transaction $Transaction, string $tag): mixed => $Transaction->await(
      $Transaction->query("INSERT INTO {$table} (tag) VALUES ('{$tag}')")
   );
   // ! Transactions the server still holds open, seen from another session.
   $probe = $driver === 'pgsql'
      ? "SELECT count(*) AS n FROM pg_stat_activity WHERE datname = current_database() AND state LIKE 'idle in transaction%'"
      : 'SELECT count(*) AS n FROM information_schema.innodb_trx';
   $Observer = $Open(1);
   $stranded = static function () use ($Observer, $probe): int {
      // @@ A dropped session is rolled back by the server on its own time.
      $deadline = microtime(true) + 3.0;

      do {
         $open = (int) $Observer->await($Observer->query($probe))->Result?->cell;

         if ($open === 0) {
            break;
         }

         usleep(50_000);
      }
      while (microtime(true) < $deadline);

      return $open;
   };
   $Failure = new LogicException('the work failed');

   $Database = $Open(2);
   $Database->await($Database->query(
      $driver === 'pgsql'
         ? "CREATE TABLE {$table} (id SERIAL PRIMARY KEY, tag TEXT NOT NULL)"
         : "CREATE TABLE {$table} (id INT AUTO_INCREMENT PRIMARY KEY, tag VARCHAR(64) NOT NULL) ENGINE=InnoDB"
   ));

   try {
      // # Commit + result passthrough — the write is invisible outside the
      //   transaction until transact() returns.
      $outside = null;
      $result = $Database->transact(static function (Transaction $Transaction) use ($Database, $mark, $table, &$outside): string {
         $mark($Transaction, 'committed');
         $outside = (int) $Database->await($Database->query("SELECT count(*) AS n FROM {$table}"))->Result?->cell;

         return 'done';
      });

      yield assert(
         assertion: $result === 'done' && $outside === 0
            && $tags($Database) === ['committed']
            && $census($Database) === [0, 0, 0],
         description: "{$driver}(live): the work's result is returned and its write committed, found: "
            . json_encode([$result, $outside, $tags($Database), $census($Database)])
      );

      // # Rollback on throw
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
            && $census($Database) === [0, 0, 0] && $stranded() === 0,
         description: "{$driver}(live): a throwing work is rolled back on the server, found: "
            . json_encode([$Caught?->getMessage(), $tags($Database), $census($Database)])
      );

      // # A level left open + nesting, on a pool with a spare connection: a
      //   second transaction would be served there, so `created` tells.
      $Warm = $Open(2);
      $Warm->await($Warm->query('SELECT 1 AS n'));
      $created = $Warm->Pool->created;
      $depths = [];

      $Warm->transact(static function (Transaction $Transaction) use ($Warm, $mark, $Failure, &$depths): void {
         $mark($Transaction, 'outer');

         try {
            $Warm->transact(static function (Transaction $Transaction) use ($mark, $Failure, &$depths): void {
               $depths[] = $Transaction->depth;
               $mark($Transaction, 'nested, rolled back');

               throw $Failure;
            });
         }
         catch (LogicException) {
            // ? The outer unit of work goes on.
         }

         $Warm->transact(static function (Transaction $Transaction) use ($mark, &$depths): void {
            $depths[] = $Transaction->depth;
            $mark($Transaction, 'nested, committed');
         });

         $Transaction->await($Transaction->begin());
         $mark($Transaction, 'left open');
      });

      yield assert(
         assertion: $depths === [2, 2]
            && $Warm->Pool->created === $created
            && $tags($Database) === ['committed', 'outer', 'nested, committed', 'left open']
            && $census($Warm) === [0, 0, 0] && $stranded() === 0,
         description: "{$driver}(live): nested calls are savepoints of one transaction on one connection, and a level left open is committed, found: "
            . json_encode([$depths, $Warm->Pool->created, $created, $tags($Database), $census($Warm)])
      );

      // # A throwing Rollback listener: the server transaction is ended anyway
      Emitter::$Instance = new Emitter;
      Emitter::$Instance->listen(Events::Rollback, static function (): void {
         throw new RuntimeException('a broken audit listener');
      });
      $Single = $Open(1);
      $Held = null;
      $Caught = null;

      try {
         $Single->transact(static function (Transaction $Transaction) use ($mark, $Failure, &$Held): void {
            $Held = $Transaction;
            $mark($Transaction, 'listener');

            throw $Failure;
         });
      }
      catch (Throwable $Throwable) {
         $Caught = $Throwable;
      }

      Emitter::$Instance = new Emitter;

      $next = (int) $Single->await($Single->query('SELECT 7 AS n'))->Result?->cell;

      yield assert(
         assertion: $Caught === $Failure && $Held?->depth === 0
            && $next === 7
            && $census($Single) === [0, 0, 0] && $stranded() === 0
            && in_array('listener', $tags($Database), true) === false,
         description: "{$driver}(live): a Rollback listener that throws leaves no open transaction and the pool of one serves the next statement, found: "
            . json_encode([$Caught?->getMessage(), $Held?->depth, $next, $census($Single)])
      );

      // # Pool of one: the statement after transact() is served after a
      //   commit and after a rollback
      $served = [];
      $Single->transact(static fn (Transaction $Transaction): mixed => $mark($Transaction, 'single'));
      $served[] = (int) $Single->await($Single->query('SELECT 1 AS n'))->Result?->cell;

      try {
         $Single->transact(static function (Transaction $Transaction) use ($Failure): void {
            $Transaction->await($Transaction->begin());
            $Transaction->await($Transaction->begin());

            throw $Failure;
         });
      }
      catch (LogicException) {
         $served[] = (int) $Single->await($Single->query('SELECT 2 AS n'))->Result?->cell;
      }

      yield assert(
         assertion: $served === [1, 2] && $census($Single) === [0, 0, 0] && $stranded() === 0,
         description: "{$driver}(live): the connection always returns to the pool, found: " . json_encode([$served, $census($Single)])
      );

      // # A statement left in flight at depth 2: withdrawn with its session,
      //   and the server rolls the transaction back
      $Held = null;
      $Caught = null;

      try {
         $Single->transact(static function (Transaction $Transaction) use ($Single, $mark, $Failure, $table, &$Held): void {
            $Held = $Transaction;
            $mark($Transaction, 'overtaken');
            $Transaction->await($Transaction->begin());
            $Single->advance($Transaction->query("INSERT INTO {$table} (tag) VALUES ('in flight')"));

            throw $Failure;
         });
      }
      catch (Throwable $Throwable) {
         $Caught = $Throwable;
      }

      yield assert(
         assertion: $Caught === $Failure && $Held?->depth === 0
            && $Held->Operation?->finished === true
            && $census($Single)[0] === 0 && $census($Single)[1] === 0
            && $stranded() === 0
            && array_intersect(['overtaken', 'in flight'], $tags($Database)) === [],
         description: "{$driver}(live): a nested level with a statement in flight is ended with the whole transaction, found: "
            . json_encode([$Caught?->getMessage(), $Held?->depth, $census($Single), $tags($Database)])
      );

      // # A COMMIT the server refuses (PostgreSQL: a deferred constraint)
      if ($driver === 'pgsql') {
         $Database->await($Database->query(
            "CREATE TABLE {$table}_children (id SERIAL PRIMARY KEY, parent INT REFERENCES {$table} (id) DEFERRABLE INITIALLY DEFERRED)"
         ));
         $Fresh = $Open(1);
         $Caught = null;

         try {
            $Fresh->transact(static function (Transaction $Transaction) use ($table): void {
               $Transaction->await($Transaction->query("INSERT INTO {$table}_children (parent) VALUES (2147483647)"));
            });
         }
         catch (Throwable $Throwable) {
            $Caught = $Throwable;
         }

         $children = (int) $Fresh->await($Fresh->query("SELECT count(*) AS n FROM {$table}_children"))->Result?->cell;

         yield assert(
            assertion: $Caught instanceof RuntimeException
               && str_contains($Caught->getMessage(), 'foreign key')
               && $children === 0
               && $census($Fresh) === [0, 0, 0] && $stranded() === 0,
            description: 'pgsql(live): a COMMIT the server refuses is rethrown and nothing stays reserved, found: '
               . json_encode([$Caught?->getMessage(), $children, $census($Fresh)])
         );
      }
   }
   finally {
      Emitter::$Instance = new Emitter;

      $Cleaner = $Open(1);
      $Cleaner->await($Cleaner->query("DROP TABLE IF EXISTS {$table}_children"));
      $Cleaner->await($Cleaner->query("DROP TABLE IF EXISTS {$table}"));
   }
};
