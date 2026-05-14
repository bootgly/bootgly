<?php

namespace Bootgly\ADI\Databases\SQL\Builder\Tests\UpsertOrdering;


use function assert;
use InvalidArgumentException;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Nulls;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Orders;


enum Tables: string
{
   case Users = 'users';
}

enum Columns: string
{
   case Id = 'id';
   case Name = 'name';
}


return new Specification(
   description: 'Database: SQL builder compiles upserts ordering and mutation guards',
   test: function () {
      $Query = (new Builder)
         ->table(Tables::Users)
         ->insert()
         ->set(Columns::Id, 1, 2)
         ->set(Columns::Name, 'Ada', 'Bob')
         ->output(Columns::Id)
         ->compile();

      yield assert(
         assertion: $Query->sql === 'INSERT INTO "users" ("id", "name") VALUES ($1, $2), ($3, $4) RETURNING "id"'
            && $Query->parameters === [1, 'Ada', 2, 'Bob'],
         description: 'Builder compiles multi-row INSERT values through variadic set()'
      );

      $Query = (new Builder)
         ->table(Tables::Users)
         ->insert()
         ->set(Columns::Id, 1, 2)
         ->set(Columns::Name, 'Ada', 'Bob')
         ->upsert(Columns::Id)
         ->output(Columns::Id, Columns::Name)
         ->compile();

      yield assert(
         assertion: $Query->sql === 'INSERT INTO "users" ("id", "name") VALUES ($1, $2), ($3, $4) ON CONFLICT ("id") DO UPDATE SET "name" = EXCLUDED."name" RETURNING "id", "name"'
            && $Query->parameters === [1, 'Ada', 2, 'Bob'],
         description: 'Builder compiles PostgreSQL ON CONFLICT upserts'
      );

      $Query = (new Builder)
         ->table(Tables::Users)
         ->select(Columns::Id)
         ->order(Orders::Asc, Columns::Name, Nulls::Last)
         ->compile();

      yield assert(
         assertion: $Query->sql === 'SELECT "id" FROM "users" ORDER BY "name" ASC NULLS LAST',
         description: 'Builder compiles NULLS FIRST/LAST ordering modifiers'
      );

      $Query = (new Builder)
         ->table(Tables::Users)
         ->select(Columns::Id)
         ->order(Orders::Asc, Columns::Name, Nulls::First)
         ->order(Orders::Desc, Columns::Id, Nulls::Last)
         ->compile();

      yield assert(
         assertion: $Query->sql === 'SELECT "id" FROM "users" ORDER BY "name" ASC NULLS FIRST, "id" DESC NULLS LAST',
         description: 'Builder applies NULLS modifiers per ordered column'
      );

      $Query = (new Builder)
         ->table(Tables::Users)
         ->insert()
         ->set(Columns::Id, 1)
         ->upsert(Columns::Id)
         ->compile();

      yield assert(
         assertion: $Query->sql === 'INSERT INTO "users" ("id") VALUES ($1) ON CONFLICT ("id") DO NOTHING'
            && $Query->parameters === [1],
         description: 'Builder compiles ON CONFLICT DO NOTHING when no update columns remain'
      );

      $blocked = false;

      try {
         (new Builder)
            ->table(Tables::Users)
            ->insert()
            ->set(Columns::Id, 1, 2)
            ->set(Columns::Name, 'Ada');
      }
      catch (InvalidArgumentException) {
         $blocked = true;
      }

      yield assert(
         assertion: $blocked,
         description: 'Builder rejects multi-row INSERT columns with mismatched value counts at set() time'
      );

      $blocked = false;

      try {
         (new Builder)
            ->table(Tables::Users)
            ->update()
            ->set(Columns::Name, 'Ada', 'Bob');
      }
      catch (InvalidArgumentException) {
         $blocked = true;
      }

      yield assert(
         assertion: $blocked,
         description: 'Builder rejects multiple set() values immediately in UPDATE mode'
      );

      $blocked = false;

      try {
         (new Builder)
            ->table(Tables::Users)
            ->set(Columns::Name, 'Ada', 'Bob')
            ->update();
      }
      catch (InvalidArgumentException) {
         $blocked = true;
      }

      yield assert(
         assertion: $blocked,
         description: 'Builder rejects earlier multi-value assignments when entering UPDATE mode'
      );
   }
);
