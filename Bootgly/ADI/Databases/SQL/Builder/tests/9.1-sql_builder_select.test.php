<?php

namespace Bootgly\ADI\Databases\SQL\Builder\Tests\Select;


use function assert;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Operators;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Orders;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;


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
   description: 'Database: SQL builder compiles SELECT with Bootgly fluent verbs',
   test: function () {
      $Builder = new Builder;
      $Query = $Builder
         ->table(Tables::Users)
         ->select(Columns::Id, Columns::Name)
         ->filter(Columns::Active, Operators::Equal, true)
         ->filter(Columns::Id, Operators::Greater, 10)
         ->group(Columns::Id, Columns::Name)
         ->order(Orders::Asc, Columns::Name)
         ->limit(10, 5)
         ->compile();

      yield assert(
         assertion: $Query->sql === 'SELECT "id", "name" FROM "users" WHERE "active" = $1 AND "id" > $2 GROUP BY "id", "name" ORDER BY "name" ASC LIMIT 10 OFFSET 5',
         description: 'Builder compiles SELECT with filter, group, order and limit clauses'
      );

      yield assert(
         assertion: $Query->parameters === [true, 10],
         description: 'Builder extracts SELECT filter parameters in order'
      );

      $Identifier = new Identifier('public.users');
      $Query = (new Builder)
         ->table($Identifier)
         ->select()
         ->filter(Columns::Id, Operators::In, [1, 2, 3])
         ->compile();

      yield assert(
         assertion: $Query->sql === 'SELECT * FROM "public"."users" WHERE "id" IN ($1, $2, $3)',
         description: 'Builder compiles wildcard SELECT and IN filters with quoted identifier objects'
      );

      yield assert(
         assertion: $Query->parameters === [1, 2, 3],
         description: 'Builder expands IN values as ordered parameters'
      );
   }
);
