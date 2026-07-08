<?php

namespace Bootgly\ADI\Databases\SQL\Repository\Tests\PaginatingPage;


use function assert;
use function count;
use RuntimeException;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder;
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
   description: 'Database: SQL ORM page pagination pipelines items and COUNT(*) operations',
   test: function () {
      $Database = new RecordingSQL;
      $Repository = $Database->map(User::class);

      $Selection = $Repository
         ->select()
         ->filter(new Identifier('active'), Operators::Equal, true)
         ->order(Orders::Asc, new Identifier('name'));
      $Pagination = new Pagination(limit: 5, page: 2);
      $Items = $Repository->paginate($Selection, $Pagination);

      yield assert(
         assertion: count($Database->queries) === 2
            && $Database->queries[0]['sql'] === 'SELECT "id", "name", "email", "active" FROM "orm_users" WHERE "active" = ?1 ORDER BY "name" ASC, "id" ASC LIMIT 5 OFFSET 5'
            && $Database->queries[0]['parameters'] === [true]
            && $Database->queries[1]['sql'] === 'SELECT COUNT(*) AS "total" FROM "orm_users" WHERE "active" = ?1'
            && $Database->queries[1]['parameters'] === [true],
         description: 'Repository::paginate dispatches the sliced items query and one pipelined COUNT(*)'
      );

      yield assert(
         assertion: $Selection->compile()->SQL === 'SELECT "id", "name", "email", "active" FROM "orm_users" WHERE "active" = ?1 ORDER BY "name" ASC',
         description: 'Repository::paginate clones the given selection instead of mutating it'
      );

      $rows = [];
      for ($id = 6; $id <= 10; $id++) {
         $rows[] = ['id' => $id, 'name' => "User {$id}", 'email' => "u{$id}@example.test", 'active' => true];
      }
      $Items->resolve(new Result(rows: $rows, columns: ['id', 'name', 'email', 'active']));
      $Database->Operations[1]->resolve(new Result(rows: [['total' => 23]], columns: ['total']));

      $Mapped = $Repository->hydrate($Items);

      yield assert(
         assertion: $Mapped->Pagination === $Pagination
            && $Pagination->total === 23
            && $Pagination->pages === 5
            && $Pagination->more === true
            && $Pagination->next === null
            && $Mapped->count === 5,
         description: 'Repository::hydrate resolves the pipelined total into the pagination outcome'
      );

      // # Defaults: bare pagination adds the primary-key tiebreak and no offset.
      $Bare = $Repository->paginate();

      yield assert(
         assertion: $Database->queries[2]['sql'] === 'SELECT "id", "name", "email", "active" FROM "orm_users" ORDER BY "id" ASC LIMIT 10'
            && $Database->queries[3]['sql'] === 'SELECT COUNT(*) AS "total" FROM "orm_users"',
         description: 'Repository::paginate defaults to page one with the primary-key tiebreak order'
      );

      // # Page mode requires an await bridge for a pending COUNT(*).
      $Bare->resolve(new Result(rows: [], columns: ['id', 'name', 'email', 'active']));
      $bridgeless = false;
      try {
         $Repository->hydrate($Bare);
      }
      catch (RuntimeException) {
         $bridgeless = true;
      }

      yield assert(
         assertion: $bridgeless,
         description: 'Repository::hydrate rejects pending COUNT(*) operations without an await bridge'
      );
   }
);
