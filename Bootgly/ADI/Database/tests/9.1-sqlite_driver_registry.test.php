<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Operation as DatabaseOperation;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers;
use Bootgly\ADI\Databases\SQL\Drivers\SQLite;


return new Specification(
   description: 'SQLite: driver registered in the SQL drivers registry',
   test: function () {
      $Config = new Config(['driver' => 'sqlite', 'database' => ':memory:']);
      $Connection = new Connection($Config);
      $Drivers = new Drivers($Config, $Connection);

      $Driver = $Drivers->fetch('sqlite');

      yield assert(
         assertion: $Driver instanceof SQLite,
         description: 'Registry resolves `sqlite` to the SQLite driver'
      );

      yield assert(
         assertion: $Driver->Config === $Config && $Driver->Connection === $Connection,
         description: 'Driver holds the shared Config and Connection'
      );

      $Rejected = $Driver->prepare(new DatabaseOperation($Connection));

      yield assert(
         assertion: $Rejected->finished && $Rejected->error === 'SQLite requires an SQL operation.',
         description: 'Non-SQL operations fail with a clear message'
      );

      $Cancelled = $Driver->cancel($Driver->query('SELECT 1'));

      yield assert(
         assertion: $Cancelled->error === 'Database driver does not support cancellation.'
            || $Cancelled->error === 'SQLite driver requires the sqlite3 extension.',
         description: 'Cancellation is unsupported (inherited driver default)'
      );
   }
);
