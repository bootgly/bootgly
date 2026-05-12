<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database;
use Bootgly\ADI\Databases;
use Bootgly\ADI\Databases\SQL;


return new Specification(
   description: 'Database: Databases registry resolves paradigm facades',
   test: function () {
      $Databases = new Databases;
      $class = $Databases->resolve('sql');
      $Database = $Databases->create('sql');

      yield assert(
         assertion: $class === SQL::class,
         description: 'Databases registry resolves the SQL paradigm class'
      );

      yield assert(
         assertion: $Database instanceof SQL && $Database instanceof Database,
         description: 'Databases registry creates SQL as a Database subclass'
      );
   }
);
