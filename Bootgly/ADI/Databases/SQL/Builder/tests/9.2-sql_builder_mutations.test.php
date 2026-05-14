<?php

namespace Bootgly\ADI\Databases\SQL\Builder\Tests\Mutations;


use function assert;
use InvalidArgumentException;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Operators;


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
   description: 'Database: SQL builder compiles INSERT UPDATE DELETE statements',
   test: function () {
      $Query = (new Builder)
         ->table(Tables::Users)
         ->insert()
         ->set(Columns::Name, 'Ada')
         ->set(Columns::Active, true)
         ->compile();

      yield assert(
         assertion: $Query->sql === 'INSERT INTO "users" ("name", "active") VALUES ($1, $2)',
         description: 'Builder compiles INSERT with assigned columns'
      );

      yield assert(
         assertion: $Query->parameters === ['Ada', true],
         description: 'Builder extracts INSERT values in assignment order'
      );

      $Query = (new Builder)
         ->table(Tables::Users)
         ->update()
         ->set(Columns::Active, false)
         ->filter(Columns::Id, Operators::Equal, 7)
         ->compile();

      yield assert(
         assertion: $Query->sql === 'UPDATE "users" SET "active" = $1 WHERE "id" = $2',
         description: 'Builder compiles guarded UPDATE with filters'
      );

      yield assert(
         assertion: $Query->parameters === [false, 7],
         description: 'Builder extracts UPDATE assignments before filters'
      );

      $Query = (new Builder)
         ->table(Tables::Users)
         ->delete()
         ->filter(Columns::Id, Operators::Equal, 7)
         ->compile();

      yield assert(
         assertion: $Query->sql === 'DELETE FROM "users" WHERE "id" = $1',
         description: 'Builder compiles guarded DELETE with filters'
      );

      yield assert(
         assertion: $Query->parameters === [7],
         description: 'Builder extracts DELETE filter parameters'
      );

      $blocked = false;

      try {
         (new Builder)
            ->table(Tables::Users)
            ->delete()
            ->compile();
      }
      catch (InvalidArgumentException) {
         $blocked = true;
      }

      yield assert(
         assertion: $blocked,
         description: 'Builder blocks global DELETE without filters'
      );
   }
);
