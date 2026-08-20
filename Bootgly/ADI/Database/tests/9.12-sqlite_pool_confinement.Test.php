<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Pool;
use Bootgly\ADI\Databases\SQL;


return new Test(
   description: 'SQLite: a database private to its handle gets a pool of one connection',
   skip: extension_loaded('sqlite3') === false,
   test: function () {
      $pool = static fn (array $config): array => (new SQL($config))->Config->pool;
      $counted = static function (Pool $Pool): int {
         return count((new ReflectionProperty(Pool::class, 'counted'))->getValue($Pool));
      };
      $cell = static function (SQL $Database, string $SQL): mixed {
         $Operation = $Database->query($SQL);

         try { $Database->await($Operation); }
         catch (Throwable $Throwable) { return $Throwable->getMessage(); }

         return $Operation->Result?->cell;
      };

      // # The configuration contract

      yield assert(
         assertion: $pool(['driver' => 'sqlite', 'database' => ':memory:'])['max'] === 1
            && $pool(['driver' => 'sqlite', 'database' => ':memory:', 'pool' => ['max' => 8]])['max'] === 1
            && $pool(['driver' => 'sqlite', 'database' => ''])['max'] === 1,
         description: 'A database private to its handle gets one connection, however many were asked for'
      );

      // ? The clamp is about sharing, not about opening: a pool told to open nothing
      //   still opens nothing, which is what the ORM specs configure.
      yield assert(
         assertion: $pool(['driver' => 'sqlite', 'database' => ':memory:', 'pool' => ['max' => 1]])['max'] === 1
            && $pool(['driver' => 'sqlite', 'database' => ':memory:', 'pool' => ['min' => 0, 'max' => 0]]) === ['min' => 0, 'max' => 0]
            && $pool(['driver' => 'sqlite', 'database' => ':memory:', 'pool' => ['min' => 5, 'max' => 0]]) === ['min' => 5, 'max' => 0],
         description: 'A pool of one, and a pool of none, are both left exactly as configured'
      );

      yield assert(
         assertion: $pool(['driver' => 'sqlite', 'database' => ':memory:', 'pool' => ['min' => 4, 'max' => 8]])
            === ['min' => 1, 'max' => 1],
         description: 'The floor follows the ceiling down, so the pair stays satisfiable'
      );

      // @@ Control — every other driver keeps the pool it was given.
      yield assert(
         assertion: $pool(['driver' => 'pgsql'])['max'] === 8
            && $pool(['driver' => 'mysql', 'pool' => ['min' => 2, 'max' => 6]]) === ['min' => 2, 'max' => 6],
         description: 'Drivers that share one database across handles keep their pool'
      );

      // @@ Control — a FILE database is shared between handles, so it keeps its pool.
      //    Confining it would refuse work the engine can serve: a read issued while a
      //    transaction holds a connection, and any query a resource routes to the facade.
      yield assert(
         assertion: $pool(['driver' => 'sqlite', 'database' => '/tmp/bootgly-shared.db'])['max'] === 8
            && $pool(['driver' => 'sqlite', 'pool' => ['min' => 4, 'max' => 8]]) === ['min' => 4, 'max' => 8],
         description: 'A file database keeps every connection it was given'
      );

      // # Replicas — a replica declares its own driver, database and pool

      $Replicated = new SQL([
         'driver' => 'sqlite',
         'database' => ':memory:',
         'replicas' => [['host' => 'ignored'], ['host' => 'ignored', 'database' => '/tmp/bootgly-replica.db']],
      ]);
      $ReplicaPools = (new ReflectionProperty(SQL::class, 'ReplicaPools'))->getValue($Replicated);

      yield assert(
         assertion: $Replicated->SQLConfig->replicas[0]['pool']['max'] === 1
            && $ReplicaPools[0]->max === 1
            && $Replicated->SQLConfig->replicas[1]['pool']['max'] === 8
            && $ReplicaPools[1]->max === 8,
         description: 'A replica is judged on its own database, where it is declared and where it is opened'
      );

      // # The behaviour the contract buys: :memory: stays one database

      $Database = new SQL(['driver' => 'sqlite', 'database' => ':memory:', 'timeout' => 5.0]);

      $cell($Database, 'CREATE TABLE t (id INTEGER PRIMARY KEY)');
      $cell($Database, 'INSERT INTO t (id) VALUES (1)');

      // ! A transaction locks its connection, so a plain query cannot co-locate:
      //   this is the moment an unconfined pool opens a second, empty database.
      $Transaction = $Database->begin();
      $Database->await($Transaction->Operation);

      $cell($Database, 'SELECT count(*) AS n FROM t');
      $connections = $counted($Database->Pool);

      $Database->await($Transaction->commit());

      yield assert(
         assertion: $connections === 1,
         description: 'A held connection never buys a second database, found: ' . $connections
      );

      $read = [];

      for ($attempt = 0; $attempt < 4; $attempt++) {
         $read[] = $cell($Database, 'SELECT count(*) AS n FROM t');
      }

      yield assert(
         assertion: $read === [1, 1, 1, 1],
         description: 'Identical reads agree, instead of alternating between two databases, found: '
            . json_encode($read)
      );
   }
);
