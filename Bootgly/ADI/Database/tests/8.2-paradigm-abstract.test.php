<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database;
use Bootgly\ADI\Databases\SQL;


return new Specification(
   description: 'Database: singular Database is abstract and SQL owns query semantics',
   test: function () {
      $Database = new ReflectionClass(Database::class);
      $SQL = new ReflectionClass(SQL::class);

      yield assert(
         assertion: $Database->isAbstract(),
         description: 'Singular Database is an abstract transport core'
      );

      yield assert(
         assertion: $SQL->isSubclassOf(Database::class),
         description: 'SQL facade extends the singular Database core'
      );

      yield assert(
         assertion: $Database->hasMethod('query') === false && $SQL->hasMethod('query'),
         description: 'SQL query semantics live on the SQL paradigm facade only'
      );
   }
);
