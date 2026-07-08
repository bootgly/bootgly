<?php

use function array_keys;
use function count;
use function extension_loaded;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Drivers\SQLite;


return new Specification(
   description: 'SQLite: prepared statement cache reuses and evicts by LRU',
   skip: extension_loaded('sqlite3') === false,
   test: function () {
      $Database = new SQL([
         'driver' => 'sqlite',
         'database' => ':memory:',
         'statements' => 2,
      ]);

      $Database->query('CREATE TABLE cached (id INTEGER PRIMARY KEY, value INTEGER)');
      $Database->query('INSERT INTO cached (value) VALUES (10), (20), (30)');

      $first = 'SELECT value FROM cached WHERE id = ?1';
      $second = 'SELECT value FROM cached WHERE value > ?1';
      $third = 'SELECT count(*) AS total FROM cached WHERE value < ?1';

      $First = $Database->query($first, [1]);
      $Driver = $First->Protocol;

      yield assert(
         assertion: $Driver instanceof SQLite && $First->prepared && $First->Result?->cell === 10,
         description: 'Parameterized queries run through prepared statements'
      );

      /** @var SQLite $Driver */

      $Database->query($second, [15]);

      yield assert(
         assertion: array_keys($Driver->statements) === [$first, $second],
         description: 'Cache stores prepared statements up to the configured cap'
      );

      // @ LRU touch — reusing the first statement moves it to the end.
      $Reused = $Database->query($first, [2]);

      yield assert(
         assertion: $Reused->Result?->cell === 20
            && array_keys($Driver->statements) === [$second, $first],
         description: 'Reuse rebinds fresh parameters and touches the LRU order'
      );

      // @ Overflow — the least recently used statement is evicted.
      $Database->query($third, [25]);

      yield assert(
         assertion: array_keys($Driver->statements) === [$first, $third]
            && count($Driver->statements) === 2,
         description: 'Cache evicts the least recently used statement at the cap'
      );

      // # statements => 0 disables caching entirely
      $Uncached = new SQL([
         'driver' => 'sqlite',
         'database' => ':memory:',
         'statements' => 0,
      ]);
      $Uncached->query('CREATE TABLE direct (id INTEGER PRIMARY KEY, value INTEGER)');
      $Inserted = $Uncached->query('INSERT INTO direct (value) VALUES (?1)', [42]);
      $Fetched = $Uncached->query('SELECT value FROM direct WHERE value = ?1', [42]);
      $Direct = $Fetched->Protocol;

      yield assert(
         assertion: $Direct instanceof SQLite
            && $Inserted->prepared
            && $Fetched->Result?->cell === 42
            && $Direct->statements === [],
         description: 'statements => 0 keeps prepared execution but stores nothing in the cache'
      );
   }
);
