<?php

namespace Bootgly\ADI\Databases\SQL\Builder\Tests\Essentials;


use function assert;
use InvalidArgumentException;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Aggregates;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Joins;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Junctions;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Locks;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Operators;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Orders;
use Bootgly\ADI\Databases\SQL\Builder\Expression;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;


enum Tables: string
{
   case Profiles = 'profiles';
   case Users = 'users';
}

enum Columns: string
{
   case Active = 'active';
   case Id = 'id';
   case Name = 'name';
   case ProfilesUser = 'profiles.user_id';
   case UsersId = 'users.id';
}

enum Aliases: string
{
   case Current = 'current';
   case Total = 'total';
   case P = 'p';
   case Username = 'username';
   case U = 'u';
}


return new Specification(
   description: 'Database: SQL builder compiles aliases aggregates filters and expressions',
   test: function () {
      $Query = (new Builder)
         ->table(Tables::Users)
         ->alias(Tables::Users, Aliases::U)
         ->select(Columns::Id, Columns::Name)
         ->alias(Columns::Name, Aliases::Username)
         ->filter(Columns::Id, Operators::Between, [1, 10])
         ->lock(Locks::Update)
         ->compile();

      yield assert(
         assertion: $Query->sql === 'SELECT "id", "name" AS "username" FROM "users" AS "u" WHERE "id" BETWEEN $1 AND $2 FOR UPDATE',
         description: 'Builder compiles aliases, BETWEEN filters and row locks'
      );

      yield assert(
         assertion: $Query->parameters === [1, 10],
         description: 'Builder extracts BETWEEN values in order'
      );

      $Query = (new Builder)
         ->table(Tables::Users)
         ->count(Aliases::Total)
         ->compile();

      yield assert(
         assertion: $Query->sql === 'SELECT COUNT(*) AS "total" FROM "users"',
         description: 'Builder compiles COUNT with alias'
      );

      $Query = (new Builder)
         ->table(Tables::Users)
         ->aggregate(Aggregates::Maximum, Columns::Id, Aliases::Total)
         ->compile();

      yield assert(
         assertion: $Query->sql === 'SELECT MAX("id") AS "total" FROM "users"',
         description: 'Builder compiles generic aggregate with alias'
      );

      $Query = (new Builder)
         ->table(Tables::Users)
         ->aggregate(Aggregates::Maximum, Columns::Id, Aliases::Total)
         ->select(Columns::Name)
         ->compile();

      yield assert(
         assertion: $Query->sql === 'SELECT MAX("id") AS "total", "name" FROM "users"',
         description: 'Builder preserves aggregate projections before select() additions'
      );

      $Query = (new Builder)
         ->table(Tables::Users)
         ->select(Columns::Name)
         ->count(Aliases::Total)
         ->compile();

      yield assert(
         assertion: $Query->sql === 'SELECT "name", COUNT(*) AS "total" FROM "users"',
         description: 'Builder accumulates select() and count() projections in call order'
      );

      $Query = (new Builder)
         ->table(Tables::Users)
         ->insert()
         ->set(Columns::Id, 1)
         ->set(Columns::Name, 'Ada')
         ->output(Columns::Id)
         ->output(Columns::Name)
         ->compile();

      yield assert(
         assertion: $Query->sql === 'INSERT INTO "users" ("id", "name") VALUES ($1, $2) RETURNING "id", "name"',
         description: 'Builder compiles INSERT RETURNING through output()'
      );

      yield assert(
         assertion: $Query->parameters === [1, 'Ada'],
         description: 'Builder keeps mutation parameters before RETURNING output columns'
      );

      $Query = (new Builder)
         ->table(Tables::Users)
         ->alias(Tables::Users, Aliases::U)
         ->select(Columns::Name)
         ->alias(Columns::Name, Aliases::Username)
         ->join(Tables::Profiles, new Identifier('u.id'), Operators::Equal, new Identifier('p.user_id'), Joins::Left)
         ->alias(Tables::Profiles, Aliases::P)
         ->group(Columns::Name)
         ->order(Orders::Asc, Columns::Name)
         ->compile();

      yield assert(
         assertion: $Query->sql === 'SELECT "name" AS "username" FROM "users" AS "u" LEFT JOIN "profiles" AS "p" ON "u"."id" = "p"."user_id" GROUP BY "username" ORDER BY "username" ASC',
         description: 'Builder applies aliases across FROM, JOIN, GROUP and ORDER contexts'
      );

      $Query = (new Builder)
         ->alias(Tables::Users, Aliases::U)
         ->table(Tables::Users)
         ->select(Columns::UsersId)
         ->compile();

      yield assert(
         assertion: $Query->sql === 'SELECT "u"."id" FROM "users" AS "u"',
         description: 'Builder promotes table aliases registered before table()'
      );

      $Query = (new Builder)
         ->table(Tables::Users)
         ->alias(Tables::Profiles, Aliases::P)
         ->select(Columns::UsersId)
         ->join(Tables::Profiles, Columns::UsersId, Operators::Equal, Columns::ProfilesUser, Joins::Left)
         ->filter(Columns::ProfilesUser, Operators::IsNotNull)
         ->compile();

      yield assert(
         assertion: $Query->sql === 'SELECT "users"."id" FROM "users" LEFT JOIN "profiles" AS "p" ON "users"."id" = "p"."user_id" WHERE "p"."user_id" IS NOT NULL',
         description: 'Builder promotes join aliases registered before join()'
      );

      $blocked = false;

      try {
         (new Builder)
            ->alias(Columns::Name, Aliases::Username)
            ->alias(new Expression('"name"'), Aliases::Current);
      }
      catch (InvalidArgumentException) {
         $blocked = true;
      }

      yield assert(
         assertion: $blocked,
         description: 'Builder rejects ambiguous column and expression aliases for the same SQL text'
      );

      $blocked = false;

      try {
         (new Builder)->filter(Columns::Id, Operators::In, []);
      }
      catch (InvalidArgumentException) {
         $blocked = true;
      }

      yield assert(assertion: $blocked, description: 'Builder validates invalid IN filters before compile()');

      $Query = (new Builder)
         ->table(Tables::Users)
         ->select(Columns::Id)
         ->filter(Columns::Active, Operators::IsTrue)
         ->filter(Columns::Name, Operators::IsNotNull)
         ->compile();

      yield assert(
         assertion: $Query->sql === 'SELECT "id" FROM "users" WHERE "active" IS TRUE AND "name" IS NOT NULL'
            && $Query->parameters === [],
         description: 'Builder compiles explicit SQL literal filters without values'
      );

      $blocked = false;

      try {
         (new Builder)->filter(Columns::Id, Operators::IsNull, 1);
      }
      catch (InvalidArgumentException) {
         $blocked = true;
      }

      yield assert(assertion: $blocked, description: 'Builder rejects values for explicit SQL literal filters');

      $blocked = false;

      try {
         (new Builder)->match(Columns::Name, 42);
      }
      catch (InvalidArgumentException) {
         $blocked = true;
      }

      yield assert(assertion: $blocked, description: 'Builder rejects non-string text match values');

      $Query = (new Builder)
         ->table(Tables::Users)
         ->alias(Tables::Users, Aliases::U)
         ->select(Columns::UsersId)
         ->join(Tables::Profiles, Columns::UsersId, Operators::Equal, Columns::ProfilesUser, Joins::Left)
         ->alias(Tables::Profiles, Aliases::P)
         ->filter(Columns::UsersId, Operators::Equal, 1)
         ->filter(Columns::ProfilesUser, Operators::IsNotNull)
         ->compile();

      yield assert(
         assertion: $Query->sql === 'SELECT "u"."id" FROM "users" AS "u" LEFT JOIN "profiles" AS "p" ON "u"."id" = "p"."user_id" WHERE "u"."id" = $1 AND "p"."user_id" IS NOT NULL'
            && $Query->parameters === [1],
         description: 'Builder rewrites qualified references through table aliases in SELECT, JOIN and WHERE'
      );

      $Query = (new Builder)
         ->table(Tables::Users)
         ->distinct()
         ->select(Columns::Name)
         ->group(Columns::Name)
         ->having(Columns::Name, Operators::IsNotNull)
         ->compile();

      yield assert(
         assertion: $Query->sql === 'SELECT DISTINCT "name" FROM "users" GROUP BY "name" HAVING "name" IS NOT NULL'
            && $Query->parameters === [],
         description: 'Builder compiles DISTINCT and HAVING clauses'
      );

      $Query = (new Builder)
         ->table(Tables::Users)
         ->select(Columns::Id)
         ->nest(function (Builder $Group): void {
            $Group
               ->filter(Columns::Active, Operators::IsTrue)
               ->filter(Columns::Name, Operators::Equal, 'Ada', Junctions::Or);
         })
         ->filter(Columns::Id, Operators::Greater, 10)
         ->compile();

      yield assert(
         assertion: $Query->sql === 'SELECT "id" FROM "users" WHERE ("active" IS TRUE OR "name" = $1) AND "id" > $2'
            && $Query->parameters === ['Ada', 10],
         description: 'Builder preserves nested filter precedence with grouped predicates'
      );

      $Now = new Expression('NOW()');
      $LowerName = new Expression('LOWER("name")');
      $Query = (new Builder)
         ->table(Tables::Users)
         ->select($Now)
         ->alias($Now, Aliases::Current)
         ->filter($LowerName, Operators::Equal, 'ada')
         ->compile();

      yield assert(
         assertion: $Query->sql === 'SELECT NOW() AS "current" FROM "users" WHERE LOWER("name") = $1'
            && $Query->parameters === ['ada'],
         description: 'Builder compiles trusted SQL expressions without identifier quoting'
      );

      $Query = (new Builder)
         ->table(Tables::Users)
         ->update()
         ->set(Columns::Name, new Expression('LOWER("name")'))
         ->filter(Columns::Id, Operators::Equal, 1)
         ->compile();

      yield assert(
         assertion: $Query->sql === 'UPDATE "users" SET "name" = LOWER("name") WHERE "id" = $1'
            && $Query->parameters === [1],
         description: 'Builder compiles trusted SQL expressions as mutation values without binding'
      );
   }
);
