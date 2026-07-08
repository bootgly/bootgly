<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Tests\Paginating;


use function assert;
use function count;
use function preg_match;
use function str_contains;
use InvalidArgumentException;
use ReflectionClass;
use RuntimeException;

use const Bootgly\WPI;
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
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Database;


#[Table('paged_users')]
final class PagedUser
{
   #[Key]
   public null|int $id = null;
   #[Column]
   public string $name = '';
}

final class PaginatingSQL extends SQL
{
   /** @var array<int,array{sql:string,parameters:array<int|string,mixed>}> */
   public array $queries = [];


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

      // ! Inline resolution: COUNT(*) reports 23; items honor the LIMIT probe.
      if (str_contains($Operation->SQL, 'COUNT(*)')) {
         $Operation->resolve(new Result(rows: [['total' => 23]], columns: ['total']));

         return $Operation;
      }

      preg_match('/LIMIT (\d+)/', $Operation->SQL, $matches);
      $limit = (int) ($matches[1] ?? 3);
      $rows = [];
      for ($id = 1; $id <= $limit; $id++) {
         $rows[] = ['id' => $id, 'name' => "User {$id}"];
      }
      $Operation->resolve(new Result(rows: $rows, columns: ['id', 'name']));

      return $Operation;
   }
}


return new Specification(
   description: 'HTTP_Server_CLI Response Database resource paginates through request parameters',
   test: function () {
      $serve = static function (string $URI): array {
         $Response = new Response;
         /** @var Connection $Package */
         $Package = new ReflectionClass(Connection::class)->newInstanceWithoutConstructor();
         new ReflectionClass(Connection::class)->getProperty('timers')->setValue($Package, []);

         $Request = new Request;
         new ReflectionClass($Request)->getProperty('URI')->setRawValue($Request, $URI);
         $Interface = WPI;
         $Interface->Request = $Request;

         $Response->reset($Package, $Request);

         $SQL = new PaginatingSQL;
         $Resource = new Database($SQL);
         $Response->mount($Resource);

         return [$Response, $Resource, $SQL];
      };

      // # Page mode defaults.
      [$Response, $Resource, $SQL] = $serve('/users');
      $body = $Resource->paginate(PagedUser::class);

      yield assert(
         assertion: count($SQL->queries) === 2
            && $SQL->queries[0]['sql'] === 'SELECT "id", "name" FROM "paged_users" ORDER BY "id" ASC LIMIT 10'
            && $SQL->queries[1]['sql'] === 'SELECT COUNT(*) AS "total" FROM "paged_users"'
            && count($body['items']) === 10
            && $body['page'] === 1
            && $body['pages'] === 3
            && $body['limit'] === 10
            && $body['total'] === 23,
         description: 'Default requests paginate page one with the default limit and pipelined total'
      );

      yield assert(
         assertion: $Response->Header->get('X-Total-Count') === '23'
            && $Response->Header->get('Link') === '</users?page=2&limit=10>; rel="next"',
         description: 'Page one emits X-Total-Count and a single next Link relation'
      );

      // # Explicit page keeps foreign query parameters in links.
      [$Response, $Resource, $SQL] = $serve('/users?page=2&limit=5&q=ada');
      $body = $Resource->paginate(PagedUser::class);

      yield assert(
         assertion: str_contains($SQL->queries[0]['sql'], 'LIMIT 5 OFFSET 5')
            && $body['page'] === 2
            && $body['pages'] === 5
            && $Response->Header->get('Link') === '</users?limit=5&q=ada&page=3>; rel="next", </users?limit=5&q=ada&page=1>; rel="prev"',
         description: 'Explicit pages slice with OFFSET and preserve foreign query parameters in links'
      );

      // # Client limits are clamped.
      [, $Resource, $SQL] = $serve('/users?limit=999');
      $Resource->paginate(PagedUser::class);

      yield assert(
         assertion: str_contains($SQL->queries[0]['sql'], 'LIMIT 100'),
         description: 'Request limits above the cap clamp to the configured maximum'
      );

      [, $Resource, $SQL] = $serve('/users?limit=0');
      $Resource->paginate(PagedUser::class);

      yield assert(
         assertion: str_contains($SQL->queries[0]['sql'], 'LIMIT 10'),
         description: 'Invalid request limits fall back to the configured default'
      );

      // # Cursor mode: empty cursor keys select the keyset first page.
      [$Response, $Resource, $SQL] = $serve('/users?cursor=&limit=2');
      $body = $Resource->paginate(PagedUser::class);
      $token = Pagination::encode([2]);

      yield assert(
         assertion: count($SQL->queries) === 1
            && $SQL->queries[0]['sql'] === 'SELECT "id", "name" FROM "paged_users" ORDER BY "id" ASC LIMIT 3'
            && count($body['items']) === 2
            && $body['next'] === $token
            && isSet($body['total']) === false
            && $Response->Header->get('X-Total-Count') === ''
            && $Response->Header->get('Link') === "</users?limit=2&cursor={$token}>; rel=\"next\"",
         description: 'Cursor mode probes without COUNT(*) and links the next keyset page'
      );

      // # Cursor tokens compile keyset predicates.
      [, $Resource, $SQL] = $serve("/users?cursor={$token}&limit=2");
      $Resource->paginate(PagedUser::class);

      yield assert(
         assertion: $SQL->queries[0]['sql'] === 'SELECT "id", "name" FROM "paged_users" WHERE (("id" > ?1)) ORDER BY "id" ASC LIMIT 3'
            && $SQL->queries[0]['parameters'] === [2],
         description: 'Cursor tokens restrict the keyset page through bound parameters'
      );

      // # Malformed cursors are rejected.
      [, $Resource] = $serve('/users?cursor=%25%25%25');
      $invalid = false;
      try {
         $Resource->paginate(PagedUser::class);
      }
      catch (InvalidArgumentException) {
         $invalid = true;
      }

      yield assert(
         assertion: $invalid,
         description: 'Malformed client cursors raise before any query is dispatched'
      );

      // # Unbound resources reject request-driven pagination.
      $Unbound = new Database(new PaginatingSQL);
      $unbound = false;
      try {
         $Unbound->paginate(PagedUser::class);
      }
      catch (RuntimeException) {
         $unbound = true;
      }

      yield assert(
         assertion: $unbound,
         description: 'Pagination requires the resource to be bound to a response'
      );
   }
);
