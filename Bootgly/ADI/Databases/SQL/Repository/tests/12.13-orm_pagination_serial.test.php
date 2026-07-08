<?php

namespace Bootgly\ADI\Databases\SQL\Repository\Tests\PaginatingSerial;


use function assert;
use function count;
use function extension_loaded;
use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Model\Column;
use Bootgly\ADI\Databases\SQL\Model\Key;
use Bootgly\ADI\Databases\SQL\Model\Table;
use Bootgly\ADI\Databases\SQL\Normalized;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\ADI\Databases\SQL\Repository\Pagination;


#[Table('orm_serial_users')]
class User
{
   #[Key]
   public null|int $id = null;
   #[Column]
   public string $name = '';
}

/**
 * Serial surface double: pending operations reject pipelining (Transaction-like),
 * resolution happens only through the await bridge.
 */
class SerialSQL extends SQL
{
   /** @var array<int,array{sql:string,parameters:array<int|string,mixed>}> */
   public array $queries = [];
   /** @var array<int,Operation> */
   public array $Operations = [];


   public function __construct ()
   {
      parent::__construct(['driver' => 'sqlite', 'pool' => ['min' => 0, 'max' => 0]]);

      $this->pipelining = false;
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

   public function await (Operation $Operation): Operation
   {
      // ! Pending operations resolve only when awaited (async simulation).
      if ($Operation->finished === false) {
         if (str_contains($Operation->SQL, 'COUNT(*)')) {
            $Operation->resolve(new Result(rows: [['total' => 8]], columns: ['total']));
         }
         else {
            $Operation->resolve(new Result(rows: [
               ['id' => 1, 'name' => 'Ada'],
               ['id' => 2, 'name' => 'Bob'],
            ], columns: ['id', 'name']));
         }
      }

      return $Operation;
   }
}


return new Specification(
   description: 'Database: SQL ORM page pagination on serial and transaction surfaces',
   test: function () {
      // # Serial surface: COUNT(*) dispatch is deferred to hydration.
      $Database = new SerialSQL;
      $Repository = $Database->map(User::class, null, $Database);

      $Pagination = new Pagination(limit: 2, page: 1);
      $Items = $Repository->paginate(null, $Pagination);

      yield assert(
         assertion: count($Database->queries) === 1
            && str_contains($Items->SQL, 'LIMIT 2'),
         description: 'Serial surfaces receive only the items query while it is pending'
      );

      $Items = $Database->await($Items);
      $Mapped = $Repository->hydrate($Items);

      yield assert(
         assertion: count($Database->queries) === 2
            && str_contains($Database->queries[1]['sql'], 'COUNT(*)')
            && $Pagination->total === 8
            && $Pagination->pages === 4
            && $Pagination->more === true
            && $Mapped->count === 2,
         description: 'Hydration dispatches and awaits the deferred COUNT(*) through the bridge'
      );

      // # Real transaction surface on SQLite (serial, synchronous).
      if (extension_loaded('sqlite3')) {
         $SQLite = new SQL(['driver' => 'sqlite', 'database' => ':memory:']);
         $SQLite->query('CREATE TABLE orm_serial_users (id INTEGER PRIMARY KEY, name TEXT)');
         $SQLite->query("INSERT INTO orm_serial_users (name) VALUES ('Ada'), ('Bob'), ('Cid'), ('Dee'), ('Eve')");

         $Transaction = $SQLite->begin();
         $Users = $Transaction->map(User::class);

         $Paged = new Pagination(limit: 2, page: 2);
         $Mapped = $Users->hydrate($Users->paginate(null, $Paged));
         $Transaction->commit();

         yield assert(
            assertion: $Paged->total === 5
               && $Paged->pages === 3
               && $Paged->more === true
               && $Mapped->count === 2,
            description: 'Page pagination works through Transaction::map() without pipelining'
         );
      }
   }
);
