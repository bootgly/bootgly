<?php

namespace Bootgly\ADI\Databases\SQL\Repository\Tests\PaginatingE2E;


use function assert;
use function extension_loaded;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Model\Column;
use Bootgly\ADI\Databases\SQL\Model\Key;
use Bootgly\ADI\Databases\SQL\Model\Table;
use Bootgly\ADI\Databases\SQL\Repository\Pagination;
use Bootgly\ADI\Databases\SQL\Repository\Pagination\Modes;


#[Table('orm_paged_users')]
class User
{
   #[Key]
   public null|int $id = null;
   #[Column]
   public string $name = '';
}


return new Specification(
   description: 'Database: SQL ORM pagination end-to-end on SQLite',
   skip: extension_loaded('sqlite3') === false,
   test: function () {
      $Database = new SQL(['driver' => 'sqlite', 'database' => ':memory:']);
      $Database->query('CREATE TABLE orm_paged_users (id INTEGER PRIMARY KEY, name TEXT)');
      $Database->query(
         "INSERT INTO orm_paged_users (name) VALUES ('Ada'), ('Bob'), ('Cid'), ('Dee'), ('Eve'), ('Fay'), ('Gus')"
      );

      $Repository = $Database->map(User::class);

      // # Page mode against a real COUNT(*).
      $Pagination = new Pagination(limit: 3, page: 2);
      $Mapped = $Repository->hydrate($Repository->paginate(null, $Pagination));
      /** @var array<int,User> $Users */
      $Users = $Mapped->entities;
      $ids = [];
      foreach ($Users as $User) {
         $ids[] = $User->id;
      }

      yield assert(
         assertion: $Pagination->total === 7
            && $Pagination->pages === 3
            && $Pagination->more === true
            && $ids === [4, 5, 6],
         description: 'Page mode slices with LIMIT/OFFSET and resolves the real COUNT(*) total'
      );

      // # Full cursor walk: every row exactly once, in order.
      $ids = [];
      $cursor = null;
      $steps = 0;
      do {
         $Pagination = new Pagination(limit: 3, cursor: $cursor, Mode: Modes::Cursor);
         $Mapped = $Repository->hydrate($Repository->paginate(null, $Pagination));
         /** @var array<int,User> $Users */
         $Users = $Mapped->entities;
         foreach ($Users as $User) {
            $ids[] = $User->id;
         }
         $cursor = $Pagination->next;
         $steps++;
      } while ($cursor !== null && $steps < 10);

      yield assert(
         assertion: $ids === [1, 2, 3, 4, 5, 6, 7] && $steps === 3,
         description: 'Cursor walk visits every row exactly once without duplicates or skips'
      );

      // # Keyset stability: consumed rows may disappear mid-walk.
      $First = new Pagination(limit: 3, Mode: Modes::Cursor);
      $Repository->hydrate($Repository->paginate(null, $First));
      $Database->query('DELETE FROM orm_paged_users WHERE id = 2');

      $Second = new Pagination(limit: 3, cursor: $First->next, Mode: Modes::Cursor);
      $Mapped = $Repository->hydrate($Repository->paginate(null, $Second));
      /** @var array<int,User> $Users */
      $Users = $Mapped->entities;
      $ids = [];
      foreach ($Users as $User) {
         $ids[] = $User->id;
      }

      yield assert(
         assertion: $ids === [4, 5, 6],
         description: 'Keyset pages stay stable when already-consumed rows are deleted'
      );
   }
);
