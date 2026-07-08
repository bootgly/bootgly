<?php

namespace Bootgly\ADI\Databases\SQL\Repository\Tests\PaginatingCursor;


use function assert;
use function count;
use InvalidArgumentException;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Nulls;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Operators;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Orders;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Model\Column;
use Bootgly\ADI\Databases\SQL\Model\Key;
use Bootgly\ADI\Databases\SQL\Model\Table;
use Bootgly\ADI\Databases\SQL\Normalized;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\ADI\Databases\SQL\Repository\Pagination;
use Bootgly\ADI\Databases\SQL\Repository\Pagination\Modes;


#[Table('orm_users')]
class User
{
   #[Key]
   public null|int $id = null;
   #[Column]
   public string $name = '';
   #[Column('email')]
   public string $mail = '';
   #[Column(nullable: true)]
   public null|bool $active = null;
}

class RecordingSQL extends SQL
{
   /** @var array<int,array{sql:string,parameters:array<int|string,mixed>}> */
   public array $queries = [];
   /** @var array<int,Operation> */
   public array $Operations = [];


   public function __construct ()
   {
      parent::__construct(['driver' => 'sqlite', 'pool' => ['min' => 0, 'max' => 0]]);
   }

   /**
    * @param string|Builder|Query $query
    * @param array<int|string,mixed> $parameters
    */
   public function query (string|Builder|Query $query, array $parameters = [], null|object $Scope = null): Operation
   {
      $Normalized = new Normalized($query, $parameters);
      $Operation = new Operation(null, $Normalized->SQL, $Normalized->parameters);
      $this->queries[] = [
         'sql' => $Operation->SQL,
         'parameters' => $Operation->parameters,
      ];
      $this->Operations[] = $Operation;

      return $Operation;
   }
}


return new Specification(
   description: 'Database: SQL ORM cursor pagination compiles keyset probes and tokens',
   test: function () {
      $Database = new RecordingSQL;
      $Repository = $Database->map(User::class);
      $columns = ['id', 'name', 'email', 'active'];

      // # First keyset page: no restriction, one probe row beyond the limit.
      $Selection = $Repository->select()->order(Orders::Asc, new Identifier('name'));
      $Pagination = new Pagination(limit: 2, Mode: Modes::Cursor);
      $Items = $Repository->paginate($Selection, $Pagination);

      yield assert(
         assertion: count($Database->queries) === 1
            && $Items->SQL === 'SELECT "id", "name", "email", "active" FROM "orm_users" ORDER BY "name" ASC, "id" ASC LIMIT 3'
            && $Items->parameters === [],
         description: 'Cursor mode probes limit+1 rows without a COUNT(*) operation'
      );

      $Items->resolve(new Result(rows: [
         ['id' => 5, 'name' => 'Ada', 'email' => 'ada@example.test', 'active' => true],
         ['id' => 7, 'name' => 'Bob', 'email' => 'bob@example.test', 'active' => true],
         ['id' => 9, 'name' => 'Cid', 'email' => 'cid@example.test', 'active' => true],
      ], columns: $columns));
      $Mapped = $Repository->hydrate($Items);

      yield assert(
         assertion: $Mapped->count === 2
            && $Pagination->more === true
            && $Pagination->next === Pagination::encode(['Bob', 7])
            && $Pagination->total === null
            && $Mapped->Pagination === $Pagination,
         description: 'Cursor hydration trims the probe row and derives the next token from the last kept row'
      );

      yield assert(
         assertion: $Mapped->Result->count === 2
            && count($Mapped->Result->rows) === 2
            && $Mapped->Result->rows[1]['id'] === 7,
         description: 'Cursor hydration trims the probe row from the raw result views too'
      );

      // # Second keyset page restricted by the emitted token.
      $Next = new Pagination(limit: 2, cursor: $Pagination->next);
      $Items = $Repository->paginate($Selection, $Next);

      yield assert(
         assertion: $Items->SQL === 'SELECT "id", "name", "email", "active" FROM "orm_users" WHERE (("name" > ?1) OR ("name" = ?2 AND "id" > ?3)) ORDER BY "name" ASC, "id" ASC LIMIT 3'
            && $Items->parameters === ['Bob', 'Bob', 7],
         description: 'Cursor tokens compile to keyset OR-chain predicates with the key tiebreak'
      );

      $Items->resolve(new Result(rows: [
         ['id' => 11, 'name' => 'Dee', 'email' => 'dee@example.test', 'active' => true],
         ['id' => 13, 'name' => 'Eve', 'email' => 'eve@example.test', 'active' => true],
      ], columns: $columns));
      $Mapped = $Repository->hydrate($Items);

      yield assert(
         assertion: $Mapped->count === 2
            && $Next->more === false
            && $Next->next === null,
         description: 'Cursor hydration reports exhaustion when the probe row is absent'
      );

      // # Descending order keeps existing filters and flips the comparison.
      $Descending = $Repository
         ->select()
         ->filter(new Identifier('active'), Operators::Equal, true)
         ->order(Orders::Desc, new Identifier('name'));
      $Items = $Repository->paginate($Descending, new Pagination(limit: 2, cursor: Pagination::encode(['Bob', 7])));

      yield assert(
         assertion: $Items->SQL === 'SELECT "id", "name", "email", "active" FROM "orm_users" WHERE "active" = ?1 AND (("name" < ?2) OR ("name" = ?3 AND "id" > ?4)) ORDER BY "name" DESC, "id" ASC LIMIT 3'
            && $Items->parameters === [true, 'Bob', 'Bob', 7],
         description: 'Descending keyset pagination compiles strict Less comparisons after user filters'
      );

      // # Empty keyset pages hydrate without errors.
      $Empty = new Pagination(limit: 2, Mode: Modes::Cursor);
      $Items = $Repository->paginate(null, $Empty);
      $Items->resolve(new Result(rows: [], columns: $columns));
      $Mapped = $Repository->hydrate($Items);

      yield assert(
         assertion: $Mapped->empty === true
            && $Empty->more === false
            && $Empty->next === null,
         description: 'Cursor hydration resolves empty pages without probe artifacts'
      );

      // # Keyset columns resolve through model property mapping.
      $Items = $Repository->paginate(
         $Repository->select()->order(Orders::Asc, new Identifier('mail')),
         new Pagination(limit: 2, cursor: Pagination::encode(['bob@example.test', 7]))
      );

      yield assert(
         assertion: $Items->SQL === 'SELECT "id", "name", "email", "active" FROM "orm_users" WHERE (("email" > ?1) OR ("email" = ?2 AND "id" > ?3)) ORDER BY "email" ASC, "id" ASC LIMIT 3'
            && $Items->parameters === ['bob@example.test', 'bob@example.test', 7],
         description: 'Keyset predicates resolve mapped properties to their SQL columns'
      );

      // # Non-keyset-safe orders are rejected in cursor mode.
      $nullable = false;
      try {
         $Repository->paginate(
            $Repository->select()->order(Orders::Asc, new Identifier('active')),
            new Pagination(limit: 2, Mode: Modes::Cursor)
         );
      }
      catch (InvalidArgumentException) {
         $nullable = true;
      }

      $nulled = false;
      try {
         $Repository->paginate(
            $Repository->select()->order(Orders::Asc, new Identifier('name'), Nulls::Last),
            new Pagination(limit: 2, Mode: Modes::Cursor)
         );
      }
      catch (InvalidArgumentException) {
         $nulled = true;
      }

      yield assert(
         assertion: $nullable && $nulled,
         description: 'Cursor mode rejects nullable order columns and NULLS ordering before dispatch'
      );

      // # Malformed client tokens are rejected before dispatch.
      $invalid = false;
      try {
         $Repository->paginate(null, new Pagination(limit: 2, cursor: '%%%'));
      }
      catch (InvalidArgumentException) {
         $invalid = true;
      }

      yield assert(
         assertion: $invalid,
         description: 'Repository::paginate rejects malformed cursor tokens strictly'
      );
   }
);
