<?php

namespace Bootgly\ADI\Databases\SQL\Builder\Tests\Combinations;


use function assert;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Joins;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Matches;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Operators;


enum Tables: string
{
   case Profiles = 'profiles';
   case Users = 'users';
}

enum Columns: string
{
   case ProfilesBio = 'profiles.bio';
   case ProfilesUser = 'profiles.user_id';
   case UsersId = 'users.id';
   case UsersName = 'users.name';
}


return new Specification(
   description: 'Database: SQL builder compiles match join skip combinations',
   test: function () {
      $Query = (new Builder)
         ->table(Tables::Users)
         ->select(Columns::UsersId)
         ->join(Tables::Profiles, Columns::UsersId, Operators::Equal, Columns::ProfilesUser, Joins::Left)
         ->match(Columns::UsersName, '%ada%', Matches::Insensitive)
         ->match(Columns::ProfilesBio, 'database', Matches::Text)
         ->skip(20)
         ->compile();

      yield assert(
         assertion: $Query->sql === 'SELECT "users"."id" FROM "users" LEFT JOIN "profiles" ON "users"."id" = "profiles"."user_id" WHERE "users"."name" ILIKE $1 AND to_tsvector(\'simple\', "profiles"."bio") @@ plainto_tsquery(\'simple\', $2) OFFSET 20',
         description: 'Builder compiles JOIN, ILIKE, full-text match and OFFSET without orderBy/where syntax'
      );

      yield assert(
         assertion: $Query->parameters === ['%ada%', 'database'],
         description: 'Builder extracts match parameters in order'
      );

      $Query = (new Builder)
         ->table(Tables::Users)
         ->select(Columns::UsersId)
         ->match(Columns::UsersName, 'Ada%')
         ->compile();

      yield assert(
         assertion: $Query->sql === 'SELECT "users"."id" FROM "users" WHERE "users"."name" LIKE $1',
         description: 'Builder defaults match() to LIKE'
      );
   }
);
