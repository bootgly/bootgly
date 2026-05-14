<?php

namespace Bootgly\ADI\Databases\SQL\Builder\Tests\CTE;


use function assert;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Operators;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;
use Bootgly\ADI\Databases\SQL\Builder\Query;


enum Tables: string
{
   case Users = 'users';
}

enum Columns: string
{
   case Active = 'active';
   case Id = 'id';
   case Name = 'name';
}


return new Specification(
   description: 'Database: SQL builder compiles common table expressions',
   test: function () {
      $Recent = (new Builder)
         ->table(Tables::Users)
         ->select(Columns::Id, Columns::Name)
         ->filter(Columns::Active, Operators::Equal, true);
      $Query = (new Builder)
         ->define(new Identifier('recent'), $Recent)
         ->table(new Identifier('recent'))
         ->select(Columns::Id)
         ->filter(Columns::Name, Operators::Equal, 'Ada')
         ->compile();

      yield assert(
         assertion: $Query->sql === 'WITH "recent" AS (SELECT "id", "name" FROM "users" WHERE "active" = $1) SELECT "id" FROM "recent" WHERE "name" = $2'
            && $Query->parameters === [true, 'Ada'],
         description: 'Builder compiles common table expressions before the main query'
      );

      $Recursive = new Query('SELECT 1 AS n UNION ALL SELECT n + 1 FROM "numbers" WHERE n < $1', [3]);
      $Query = (new Builder)
         ->define(new Identifier('numbers'), $Recursive, recursive: true)
         ->table(new Identifier('numbers'))
         ->select(new Identifier('n'))
         ->compile();

      yield assert(
         assertion: $Query->sql === 'WITH RECURSIVE "numbers" AS (SELECT 1 AS n UNION ALL SELECT n + 1 FROM "numbers" WHERE n < $1) SELECT "n" FROM "numbers"'
            && $Query->parameters === [3],
         description: 'Builder compiles recursive common table expressions'
      );
   }
);
