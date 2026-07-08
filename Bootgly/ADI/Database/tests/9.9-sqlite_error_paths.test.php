<?php

use function extension_loaded;
use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL;


return new Specification(
   description: 'SQLite: failures surface the engine message and keep the connection usable',
   skip: extension_loaded('sqlite3') === false,
   test: function () {
      $Database = new SQL(['driver' => 'sqlite', 'database' => ':memory:']);

      $Broken = $Database->query('SELEC 1');

      yield assert(
         assertion: $Broken->finished && $Broken->error !== null
            && str_contains($Broken->error, 'syntax'),
         description: 'Syntax errors fail the operation with the SQLite message'
      );

      $Missing = $Database->query('SELECT * FROM missing_table');

      yield assert(
         assertion: $Missing->error !== null && str_contains($Missing->error, 'missing_table'),
         description: 'Missing tables fail with the engine error'
      );

      $Database->query('CREATE TABLE probes (id INTEGER PRIMARY KEY, value TEXT)');
      $Unbindable = $Database->query('INSERT INTO probes (value) VALUES (?1)', [['array']]);

      yield assert(
         assertion: $Unbindable->error === 'SQLite cannot bind the parameter "0".',
         description: 'Unsupported parameter types fail with a clear bind error'
      );

      $Recovered = $Database->query("INSERT INTO probes (value) VALUES ('ok')");

      yield assert(
         assertion: $Recovered->error === null && $Recovered->Result?->affected === 1,
         description: 'The connection stays usable after failed operations'
      );

      // # Foreign keys are enforced per handle (PRAGMA foreign_keys = ON)
      $Database->query('CREATE TABLE parents (id INTEGER PRIMARY KEY)');
      $Database->query('CREATE TABLE children (id INTEGER PRIMARY KEY, parent_id INTEGER REFERENCES parents (id))');
      $Orphan = $Database->query('INSERT INTO children (parent_id) VALUES (?1)', [999]);

      yield assert(
         assertion: $Orphan->error !== null && str_contains($Orphan->error, 'FOREIGN KEY'),
         description: 'Orphan child rows violate enforced foreign key constraints'
      );

      $Unwritable = new SQL(['driver' => 'sqlite', 'database' => '/proc/bootgly/impossible.db']);
      $Failed = $Unwritable->query('SELECT 1');

      yield assert(
         assertion: $Failed->finished && $Failed->error !== null,
         description: 'Unopenable database paths fail the operation gracefully'
      );
   }
);
