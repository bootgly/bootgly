<?php

namespace Bootgly\ADI\Databases\SQL\Repository\Tests\Paginating;


use function assert;
use function base64_encode;
use function rtrim;
use function strtr;
use InvalidArgumentException;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Operators;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Orders;
use Bootgly\ADI\Databases\SQL\Builder\Dialects\SQLite;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;
use Bootgly\ADI\Databases\SQL\Model\Column;
use Bootgly\ADI\Databases\SQL\Model\Key;
use Bootgly\ADI\Databases\SQL\Model\Table;
use Bootgly\ADI\Databases\SQL\Models;
use Bootgly\ADI\Databases\SQL\Repository\Pagination;
use Bootgly\ADI\Databases\SQL\Repository\Pagination\Modes;
use Bootgly\ADI\Databases\SQL\Repository\Selection;


#[Table('orm_users')]
class User
{
   #[Key]
   public null|int $id = null;
   #[Column]
   public string $name = '';
   #[Column(nullable: true)]
   public null|bool $active = null;
}


return new Specification(
   description: 'Database: SQL ORM pagination tokens and selection count/seek compile',
   test: function () {
      // # Cursor token codec
      $token = Pagination::encode(['Bob', 7]);

      yield assert(
         assertion: new Pagination(cursor: $token)->decode(2) === ['Bob', 7],
         description: 'Pagination encodes and decodes ordered cursor values'
      );

      $encoding = false;
      try {
         Pagination::encode(['Bob', null]);
      }
      catch (InvalidArgumentException) {
         $encoding = true;
      }

      yield assert(
         assertion: $encoding,
         description: 'Pagination::encode rejects null order values'
      );

      $rejected = 0;
      $invalids = [
         '%%%',                                                    // invalid base64url
         rtrim(strtr(base64_encode('{"a":1}'), '+/', '-_'), '='),  // non-list JSON
         rtrim(strtr(base64_encode('[1,2,3]'), '+/', '-_'), '='),  // wrong arity
         rtrim(strtr(base64_encode('[[1],2]'), '+/', '-_'), '='),  // nested value
         rtrim(strtr(base64_encode('[null,2]'), '+/', '-_'), '='), // null value
         '',                                                       // empty token
      ];
      foreach ($invalids as $invalid) {
         try {
            new Pagination(limit: 2, cursor: $invalid === '' ? null : $invalid, Mode: Modes::Cursor)->decode(2);
         }
         catch (InvalidArgumentException) {
            $rejected++;
         }
      }

      yield assert(
         assertion: $rejected === 6,
         description: 'Pagination::decode rejects malformed client cursors strictly'
      );

      // # Constructor guards and mode inference
      $guards = 0;
      $inputs = [
         static fn () => new Pagination(limit: 0),
         static fn () => new Pagination(page: 0),
         static fn () => new Pagination(page: 1, cursor: 'x'),
         static fn () => new Pagination(page: 2, Mode: Modes::Cursor),
      ];
      foreach ($inputs as $input) {
         try {
            $input();
         }
         catch (InvalidArgumentException) {
            $guards++;
         }
      }

      yield assert(
         assertion: $guards === 4,
         description: 'Pagination rejects invalid limits, pages and contradictory modes'
      );

      yield assert(
         assertion: new Pagination()->Mode === Modes::Page
            && new Pagination(page: 3)->Mode === Modes::Page
            && new Pagination(cursor: $token)->Mode === Modes::Cursor
            && new Pagination(Mode: Modes::Cursor)->Mode === Modes::Cursor,
         description: 'Pagination infers its mode from the given slice input'
      );

      // # Selection count and nested predicates
      $Models = new Models;
      $Model = $Models->fetch(User::class);
      $Dialect = new SQLite;

      $Selection = new Selection($Model, $Dialect);
      $Selection->filter(new Identifier('active'), Operators::Equal, true);
      $Count = $Selection->count();

      yield assert(
         assertion: $Count->SQL === 'SELECT COUNT(*) AS "total" FROM "orm_users" WHERE "active" = ?1'
            && $Count->parameters === [true],
         description: 'Selection::count compiles a total query with restriction predicates only'
      );

      $Selection->seek(
         [
            ['column' => 'name', 'order' => Orders::Asc],
            ['column' => 'id', 'order' => Orders::Asc],
         ],
         ['Bob', 7]
      );
      $Compiled = $Selection->compile();

      yield assert(
         assertion: $Compiled->SQL === 'SELECT "id", "name", "active" FROM "orm_users" WHERE "active" = ?1 AND (("name" > ?2) OR ("name" = ?3 AND "id" > ?4))'
            && $Compiled->parameters === [true, 'Bob', 'Bob', 7],
         description: 'Selection::seek compiles grouped keyset predicates through the builder'
      );

      $mismatched = false;
      try {
         $Selection->seek([['column' => 'name', 'order' => Orders::Asc]], ['Bob', 7]);
      }
      catch (InvalidArgumentException) {
         $mismatched = true;
      }

      yield assert(
         assertion: $mismatched,
         description: 'Selection::seek rejects mismatched order and value counts'
      );
   }
);
