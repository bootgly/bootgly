<?php

namespace Bootgly\ADI\Databases\SQL\Builder\Tests\Subqueries;


use function assert;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Operators;
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

enum Aliases: string
{
   case U = 'u';
}


return new Specification(
   description: 'Database: SQL builder compiles subqueries',
   test: function () {
      $Subquery = (new Builder)
         ->table(Tables::Users)
         ->select(Columns::Id)
         ->filter(Columns::Name, Operators::Equal, 'Ada');
      $Query = (new Builder)
         ->table(Tables::Users)
         ->select(Columns::Id)
         ->filter(Columns::Active, Operators::Equal, true)
         ->filter(Columns::Id, Operators::In, $Subquery)
         ->compile();

      yield assert(
         assertion: $Query->sql === 'SELECT "id" FROM "users" WHERE "active" = $1 AND "id" IN (SELECT "id" FROM "users" WHERE "name" = $2)'
            && $Query->parameters === [true, 'Ada'],
         description: 'Builder compiles IN subqueries and rebases nested placeholders'
      );

      $Subquery = (new Builder)
         ->table(Tables::Users)
         ->select(Columns::Id, Columns::Name)
         ->filter(Columns::Active, Operators::Equal, true);
      $Query = (new Builder)
         ->table($Subquery, Aliases::U)
         ->select(new Identifier('u.id'))
         ->filter(new Identifier('u.name'), Operators::Equal, 'Ada')
         ->compile();

      yield assert(
         assertion: $Query->sql === 'SELECT "u"."id" FROM (SELECT "id", "name" FROM "users" WHERE "active" = $1) AS "u" WHERE "u"."name" = $2'
            && $Query->parameters === [true, 'Ada'],
         description: 'Builder compiles derived FROM subqueries with aliases and rebased placeholders'
      );
   }
);
