<?php

namespace Bootgly\ADI\Databases\SQL\Repository\Tests\Guards;


use function array_map;
use function assert;
use function extension_loaded;
use function json_encode;
use function sort;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Junctions;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Matches;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Operators;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;
use Bootgly\ADI\Databases\SQL\Model\Column;
use Bootgly\ADI\Databases\SQL\Model\Key;
use Bootgly\ADI\Databases\SQL\Model\Table;
use Bootgly\ADI\Databases\SQL\Repository;
use Bootgly\ADI\Databases\SQL\Repository\Hooks;
use Bootgly\ADI\Databases\SQL\Repository\Pagination;
use Bootgly\ADI\Databases\SQL\Repository\Pagination\Modes;
use Bootgly\ADI\Databases\SQL\Repository\Selection;


#[Table('guarded_people')]
class Person
{
   #[Key]
   public null|int $id = null;
   #[Column]
   public string $name = '';
   #[Column]
   public string $city = '';
   #[Column]
   public int $active = 0;
}


return new Test(
   description: 'Database: SQL ORM keeps the repository\'s own predicates out of the caller\'s OR',
   skip: extension_loaded('sqlite3') === false,
   test: function () {
      $Database = new SQL(['driver' => 'sqlite', 'database' => ':memory:', 'pool' => ['min' => 0, 'max' => 1]]);
      $Database->await($Database->query('CREATE TABLE guarded_people (id INTEGER PRIMARY KEY, name TEXT, city TEXT, active INTEGER)'));
      $Database->await($Database->query(
         "INSERT INTO guarded_people (name, city, active) VALUES ('Ada', 'London', 1), ('Linus', 'Helsinki', 0), ('Grace', 'London', 1), ('Alan', 'London', 0)"
      ));

      /** @var Repository $Repository */
      $Repository = $Database->map(Person::class);
      $Repository->scope('active', static function (Selection $Selection): void {
         $Selection->filter(new Identifier('active'), Operators::Equal, 1);
      });
      /**
       * The names a selection returns, sorted.
       *
       * @return array<int,string>
       */
      $names = static function (Selection $Selection) use ($Database, $Repository): array {
         $Operation = $Repository->fetch($Selection);
         $Database->await($Operation);
         $names = array_map(static fn (array $row): string => (string) $row['name'], $Operation->rows);
         sort($names);

         return $names;
      };

      // # A named scope after a caller's OR
      //   Appended to the caller's predicates, the scope bound to the last one
      //   only — `name = 'Linus' OR (city = 'London' AND active = 1)` — and the
      //   inactive Linus came back. The caller's predicates are one group now.
      $Selection = $Repository->select()
         ->filter(new Identifier('name'), Operators::Equal, 'Linus')
         ->filter(new Identifier('city'), Operators::Equal, 'London', Junctions::Or)
         ->scope('active');
      $rows = $names($Selection);

      yield assert(
         assertion: $rows === ['Ada', 'Grace']
            && $Selection->compile()->SQL === 'SELECT "id", "name", "city", "active" FROM "guarded_people" WHERE ("name" = ?1 OR "city" = ?2) AND "active" = ?3',
         description: 'A named scope restricts the whole of a caller\'s OR, found: ' . json_encode($rows)
      );

      // # A scope with an OR of its own
      $Repository->scope('visible', static function (Selection $Selection): void {
         $Selection->filter(new Identifier('active'), Operators::Equal, 1);
         $Selection->filter(new Identifier('city'), Operators::Equal, 'Helsinki', Junctions::Or);
      });
      $Selection = $Repository->select()
         ->filter(new Identifier('city'), Operators::Equal, 'London')
         ->scope('visible');
      $rows = $names($Selection);

      yield assert(
         assertion: $rows === ['Ada', 'Grace']
            && $Selection->compile()->SQL === 'SELECT "id", "name", "city", "active" FROM "guarded_people" WHERE "city" = ?1 AND ("active" = ?2 OR "city" = ?3)',
         description: 'A scope\'s own OR stays inside its group, found: ' . json_encode($rows)
      );

      // # A scope of one predicate written with an OR junction
      //   Its junction joined it to the caller's last predicate; standing on
      //   its own after the caller's group, it is ANDed like any guard.
      $Repository->scope('loose', static function (Selection $Selection): void {
         $Selection->filter(new Identifier('active'), Operators::Equal, 1, Junctions::Or);
      });
      $Selection = $Repository->select()
         ->filter(new Identifier('city'), Operators::Equal, 'London')
         ->scope('loose');
      $rows = $names($Selection);

      yield assert(
         assertion: $rows === ['Ada', 'Grace']
            && $Selection->compile()->SQL === 'SELECT "id", "name", "city", "active" FROM "guarded_people" WHERE "city" = ?1 AND "active" = ?2',
         description: 'A lone scope predicate is ANDed whatever junction it was written with, found: ' . json_encode($rows)
      );

      // # A Selecting hook that restricts — a global scope
      $Hooked = $Database->map(Person::class);
      $Hooked->listen(Hooks::Selecting, static function (Selection $Selection): void {
         $Selection->filter(new Identifier('active'), Operators::Equal, 1);
      });
      $Selection = $Hooked->select()
         ->filter(new Identifier('name'), Operators::Equal, 'Alan')
         ->filter(new Identifier('name'), Operators::Equal, 'Linus', Junctions::Or);
      $Operation = $Hooked->fetch($Selection);
      $Database->await($Operation);

      yield assert(
         assertion: $Operation->rows === []
            && $Selection->compile()->SQL === 'SELECT "id", "name", "city", "active" FROM "guarded_people" WHERE ("name" = ?1 OR "name" = ?2) AND "active" = ?3',
         description: 'A Selecting hook restricts the whole of a caller\'s OR, found: ' . json_encode($Operation->rows)
      );

      // # Cursor pagination over a caller's OR
      //   The keyset was ANDed onto the caller's last predicate only, so the
      //   first page came back with the same token forever.
      $Cursor = $Repository->select()
         ->filter(new Identifier('name'), Operators::Equal, 'Ada')
         ->filter(new Identifier('city'), Operators::Equal, 'Helsinki', Junctions::Or);
      $Pagination = new Pagination(limit: 1, Mode: Modes::Cursor);
      $pages = [];

      // @@ Bounded: a cursor that never advances must not hang the suite.
      for ($turn = 0; $turn < 4; $turn++) {
         $Items = $Repository->paginate($Cursor, $Pagination);
         $Database->await($Items);
         $Mapped = $Repository->hydrate($Items);
         $pages[] = array_map(static fn (object $Person): string => $Person instanceof Person ? $Person->name : '', $Mapped->entities);

         if ($Pagination->next === null) {
            break;
         }

         $Pagination = new Pagination(limit: 1, cursor: $Pagination->next, Mode: Modes::Cursor);
      }

      yield assert(
         assertion: $pages === [['Ada'], ['Linus']],
         description: 'A cursor over a caller\'s OR advances and ends, found: ' . json_encode($pages)
      );

      // # The page count uses the same grouping
      $Paged = $Repository->select()
         ->filter(new Identifier('name'), Operators::Equal, 'Linus')
         ->filter(new Identifier('city'), Operators::Equal, 'London', Junctions::Or)
         ->scope('active');
      $Page = new Pagination(limit: 10, page: 1);
      $Items = $Repository->paginate($Paged, $Page);
      $Database->await($Items);
      $Repository->hydrate($Items);

      yield assert(
         assertion: $Page->total === 2,
         description: 'A page total counts what the scope lets through, found: ' . json_encode($Page->total)
      );

      // # A caller's predicates replay in call order
      //   Filters were replayed before every match, so `name OR city LIKE …
      //   AND active` compiled as `name AND active OR city LIKE …`.
      $Ordered = $Repository->select()
         ->filter(new Identifier('name'), Operators::Equal, 'Linus')
         ->match(new Identifier('city'), 'Lon%', Matches::Like, Junctions::Or)
         ->filter(new Identifier('active'), Operators::Equal, 1);
      $rows = $names($Ordered);

      yield assert(
         assertion: $rows === ['Ada', 'Grace', 'Linus']
            && $Ordered->compile()->SQL === 'SELECT "id", "name", "city", "active" FROM "guarded_people" WHERE "name" = ?1 OR "city" LIKE ?2 AND "active" = ?3',
         description: 'Filters and matches replay in call order, found: ' . json_encode($rows)
      );

      // # A guard inside a guard, and a guard that throws
      //   A nested guard is a group of its own — ANDed like its parent's, so
      //   their order is immaterial; a closure that throws after
      //   adding a predicate still leaves it grouped — left among the caller's
      //   predicates, a later `OR` reached past it.
      $Nested = $Repository->select()
         ->filter(new Identifier('name'), Operators::Equal, 'Ada')
         ->guard(static function (Selection $Selection): void {
            $Selection->filter(new Identifier('active'), Operators::Equal, 1);
            $Selection->guard(static function (Selection $Selection): void {
               $Selection->filter(new Identifier('city'), Operators::Equal, 'London');
               $Selection->match(new Identifier('city'), 'Hel%', Matches::Like, Junctions::Or);
            });
         });

      $Thrown = $Repository->select()->filter(new Identifier('name'), Operators::Equal, 'Linus');

      try {
         $Thrown->guard(static function (Selection $Selection): void {
            $Selection->filter(new Identifier('active'), Operators::Equal, 1);

            throw new \RuntimeException('guard failed');
         });
      }
      catch (\RuntimeException) {
      }

      $Thrown->filter(new Identifier('name'), Operators::Equal, 'Alan', Junctions::Or);
      $rows = $names($Thrown);

      yield assert(
         assertion: $Nested->compile()->SQL === 'SELECT "id", "name", "city", "active" FROM "guarded_people" WHERE "name" = ?1 AND ("city" = ?2 OR "city" LIKE ?3) AND "active" = ?4'
            && $rows === []
            && $Thrown->compile()->SQL === 'SELECT "id", "name", "city", "active" FROM "guarded_people" WHERE ("name" = ?1 OR "name" = ?2) AND "active" = ?3',
         description: 'Nested and throwing guards still form their own groups, found: ' . json_encode($rows)
      );

      // # A cursor over a caller's OR, a scope and a Selecting listener
      $Walked = $Database->map(Person::class);
      $Walked->scope('london', static function (Selection $Selection): void {
         $Selection->filter(new Identifier('city'), Operators::Equal, 'London');
      });
      $Walked->listen(Hooks::Selecting, static function (Selection $Selection): void {
         $Selection->filter(new Identifier('active'), Operators::Equal, 1);
      });
      $Walk = $Walked->select()
         ->filter(new Identifier('name'), Operators::Equal, 'Ada')
         ->filter(new Identifier('name'), Operators::Equal, 'Grace', Junctions::Or)
         ->filter(new Identifier('name'), Operators::Equal, 'Linus', Junctions::Or)
         ->scope('london');
      $Pagination = new Pagination(limit: 1, Mode: Modes::Cursor);
      $pages = [];

      // @@ Bounded: a cursor that never advances must not hang the suite.
      for ($turn = 0; $turn < 4; $turn++) {
         $Items = $Walked->paginate($Walk, $Pagination);
         $Database->await($Items);
         $Mapped = $Walked->hydrate($Items);
         $pages[] = array_map(static fn (object $Person): string => $Person instanceof Person ? $Person->name : '', $Mapped->entities);

         if ($Pagination->next === null) {
            break;
         }

         $Pagination = new Pagination(limit: 1, cursor: $Pagination->next, Mode: Modes::Cursor);
      }

      yield assert(
         assertion: $pages === [['Ada'], ['Grace']],
         description: 'A cursor over a caller\'s OR, a scope and a listener advances and ends, found: ' . json_encode($pages)
      );

      // # A guard that adds nothing, and a selection with no guard
      $Plain = $Repository->select()
         ->filter(new Identifier('name'), Operators::Equal, 'Ada')
         ->filter(new Identifier('name'), Operators::Equal, 'Grace', Junctions::Or)
         ->guard(static function (Selection $Selection): void {
         });

      yield assert(
         assertion: $Plain->compile()->SQL === 'SELECT "id", "name", "city", "active" FROM "guarded_people" WHERE "name" = ?1 OR "name" = ?2',
         description: 'A selection with no guard group compiles as written'
      );
   }
);
