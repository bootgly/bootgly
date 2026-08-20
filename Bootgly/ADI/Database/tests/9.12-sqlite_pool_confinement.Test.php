<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Pool;
use Bootgly\ADI\Databases\SQL;


return new Test(
   description: 'SQLite: one pool holds one connection, because one handle is one database',
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
            && $pool(['driver' => 'sqlite', 'pool' => ['max' => 8]])['max'] === 1,
         description: 'A SQLite pool is confined to one connection, however many were asked for'
      );

      // ? The clamp is about sharing, not about opening: a pool told to open nothing
      //   still opens nothing, which is what the ORM specs configure.
      yield assert(
         assertion: $pool(['driver' => 'sqlite', 'pool' => ['max' => 1]])['max'] === 1
            && $pool(['driver' => 'sqlite', 'pool' => ['min' => 0, 'max' => 0]]) === ['min' => 0, 'max' => 0],
         description: 'A pool of one, and a pool of none, are both left as configured'
      );

      yield assert(
         assertion: $pool(['driver' => 'sqlite', 'pool' => ['min' => 4, 'max' => 8]]) === ['min' => 1, 'max' => 1],
         description: 'The floor follows the ceiling down, so the pair stays satisfiable'
      );

      // @@ Control — every other driver keeps the pool it was given.
      yield assert(
         assertion: $pool(['driver' => 'pgsql'])['max'] === 8
            && $pool(['driver' => 'mysql', 'pool' => ['min' => 2, 'max' => 6]]) === ['min' => 2, 'max' => 6],
         description: 'Drivers that share one database across handles keep their pool'
      );

      // ? A file database is confined too: two handles on one file do not share a
      //   transaction, they contend for its lock.
      yield assert(
         assertion: $pool(['driver' => 'sqlite', 'database' => '/tmp/bootgly-confined.db'])['max'] === 1,
         description: 'The confinement is about the driver, not about :memory:'
      );

      // # Replicas — a replica declares its own driver and its own pool

      $Replicated = new SQL([
         'driver' => 'sqlite',
         'database' => ':memory:',
         'replicas' => [['host' => 'ignored']],
      ]);
      $ReplicaPools = (new ReflectionProperty(SQL::class, 'ReplicaPools'))->getValue($Replicated);

      yield assert(
         assertion: $Replicated->SQLConfig->replicas[0]['pool']['max'] === 1
            && $ReplicaPools[0]->max === 1,
         description: 'A SQLite replica is confined where it is declared and where it is opened'
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
