<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database;
use Bootgly\ADI\Database\Connection\Protocols\Driver;
use Bootgly\ADI\Database\Connection\Protocols\PostgreSQL;


return new Specification(
   description: 'Database: resolve PostgreSQL protocol through Pool connection cache',
   test: function () {
      $Database = new Database;
      $Operation = $Database->query('SELECT 1');

      yield assert(
         assertion: $Operation->Protocol instanceof Driver,
         description: 'Pool assigns active Protocol definition'
      );

      yield assert(
         assertion: $Operation->Protocol instanceof PostgreSQL,
         description: 'Default protocol resolves to PostgreSQL'
      );

      yield assert(
         assertion: $Database->Connection->Protocol === $Operation->Protocol,
         description: 'Connection caches protocol for reuse'
      );

      yield assert(
         assertion: $Operation->Connection === $Database->Connection,
         description: 'Database query delegates operation creation to Pool'
      );
   }
);
