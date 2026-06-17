<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Tests;


use function assert;
use function is_string;
use function str_contains;
use BackedEnum;
use Closure;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Stringable;
use WeakMap;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Operation as DatabaseOperation;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Model\Column;
use Bootgly\ADI\Databases\SQL\Model\Key;
use Bootgly\ADI\Databases\SQL\Model\Table;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\ADI\Databases\SQL\Transaction;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource\Scheduling;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Database;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\JSON;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Pre;


final class RecordingSQL extends SQL
{
   /** @var WeakMap<Operation,int> */
   public WeakMap $steps;
   /** @var array<int,string> */
   public array $advanced = [];
   /** @var array<int,null|object> */
   public array $scopes = [];
   /** @var array<int,string> */
   public array $tables = [];
   /** @var array<int,null|object> */
   public array $touches = [];
   public RecordingTransaction $Transaction;

   public function __construct ()
   {
      parent::__construct(['driver' => 'sqlite', 'pool' => ['min' => 0, 'max' => 0]]);

      $this->steps = new WeakMap;
      $this->Transaction = new RecordingTransaction;
   }

   public function query (string|Builder|Query $query, array $parameters = [], null|object $Scope = null): Operation
   {
      $this->scopes[] = $Scope;

      if ($query instanceof Builder) {
         $query = $query->compile();
      }

      $sql = is_string($query) ? $query : $query->sql;
      $parameters = $query instanceof Query ? $query->parameters : $parameters;

      return new Operation(null, $sql, $parameters);
   }

   public function touch (null|object $Scope = null): void
   {
      $this->touches[] = $Scope;
   }

   public function table (BackedEnum|Stringable|Builder|Query $Table, null|BackedEnum|Stringable $Alias = null): Builder
   {
      $Builder = new Builder;
      $Builder->table($Table, $Alias);
      $this->tables[] = $Builder->table ?? '';

      return $Builder;
   }

   public function advance (Operation $Operation): Operation
   {
      $step = ($this->steps[$Operation] ?? 0) + 1;
      $this->steps[$Operation] = $step;
      $this->advanced[] = $Operation->sql;

      if ($Operation->sql === 'WAIT' && $step === 1) {
         return $Operation;
      }

      if ($Operation->sql === 'FAIL') {
         return $Operation->fail('expected failure');
      }

      if ($Operation->sql === 'NO_RESULT') {
         return $this->finish($Operation);
      }

      return $Operation->resolve(new Result(
         status: 'SELECT 1',
         rows: [
            ['value' => 42],
         ],
         columns: ['value'],
         affected: 1
      ));
   }

   public function begin (): Transaction
   {
      return $this->Transaction;
   }

   private function finish (Operation $Operation): Operation
   {
      $Finished = new ReflectionProperty(DatabaseOperation::class, 'finished');
      $Finished->setValue($Operation, true);

      return $Operation;
   }
}

final class RecordingTransaction extends Transaction
{
   /** @var array<int,string> */
   public array $events = [];
   public bool $rollbackThrows = false;

   public function __construct ()
   {
   }

   public function query (string|Builder|Query $query, array $parameters = [], null|object $Scope = null): Operation
   {
      $this->events[] = 'query';

      return $this->operation('QUERY');
   }

   public function commit (): Operation
   {
      $this->events[] = 'commit';

      return $this->operation('COMMIT');
   }

   public function rollback (null|string $name = null): Operation
   {
      $this->events[] = 'rollback';

      if ($this->rollbackThrows) {
         throw new RuntimeException('rollback failed');
      }

      return $this->operation('ROLLBACK');
   }

   private function operation (string $sql): Operation
   {
      $Operation = new Operation(null, $sql);
      $Operation->resolve(new Result(status: $sql));

      return $Operation;
   }
}

final class SyncResource extends Resource
{
}

final class RecordingSchedulingResource extends Resource implements Scheduling
{
   private null|Closure $Wait = null;

   public function schedule (Closure $Wait): static
   {
      $this->Wait = $Wait;

      return $this;
   }

   public function wait (mixed $value = null): void
   {
      if ($this->Wait === null) {
         throw new RuntimeException('Scheduling resource is not mounted.');
      }

      ($this->Wait)($value);
   }
}

#[Table('resource_orm_users')]
final class ResourceUser
{
   #[Key]
   public null|int $id = null;
   #[Column]
   public string $name = '';
}


return new Specification(
   description: 'HTTP_Server_CLI Response Database resource awaits SQL operations',
   test: function () {
      $Response = new Response;
      /** @var Connection $Package */
      $Package = (new ReflectionClass(Connection::class))->newInstanceWithoutConstructor();
      $Request = new Request;
      $reset = static function () use ($Response, $Package, $Request): void {
         $Response->reset($Package, $Request);
      };

      $SQL = new RecordingSQL;
      $Resource = new Database($SQL);
      $Mounted = $Response->mount($Resource);
      $MountedOperation = $Mounted->query('WAIT');

      yield assert(
         assertion: $Mounted === $Resource && $Response->Resources->fetch('Database') === $Resource && $MountedOperation->finished,
         description: 'Response::mount binds and registers the database resource'
      );

      $SyncResource = new SyncResource;
      $MountedSync = $Response->mount($SyncResource);

      yield assert(
         assertion: $MountedSync === $SyncResource && $SyncResource->async === false && $Response->Resources->fetch('SyncResource') === $SyncResource,
         description: 'Response::mount registers sync resources without scheduler binding'
      );

      $Fork = clone $Response;

      yield assert(
         assertion: $Fork->Resources->fetch('Database') === $Resource
            && $Fork->Resources->fetch('SyncResource') === $SyncResource
            && $Fork->Database === $Resource
            && $Response->Resources->fetch('Database') === $Resource,
         description: 'Response::__clone forks mounted resources into the deferred clone (mount() + defer())'
      );

      $prepared = $Response->prepared;
      $processed = $Response->processed;
      $Dynamic = $Response->Database;

      yield assert(
         assertion: $Dynamic === $Resource && $Response->prepared === $prepared && $Response->processed === $processed,
         description: 'Response::__get returns mounted resources without mutating response state'
      );

      $reset();
      $JSON = $Response->JSON;

      yield assert(
         assertion: $JSON instanceof JSON,
         description: 'Response exposes JSON as a built-in response resource'
      );

      $JSON->send(['status' => 'ok']);
      $jsonSent = $Response->Body->raw === '{"status":"ok"}'
         && $Response->Header->type === 'application/json';
      $reset();
      $JSONAfterReset = $Response->JSON;

      yield assert(
         assertion: $jsonSent
            && $JSONAfterReset === $JSON
            && $JSON->persistent,
         description: 'JSON resource formats content and persists after lazy first use'
      );

      $reset();
      $Pre = $Response->Pre;
      $Pre->send(['status' => 'ok']);

      yield assert(
         assertion: $Pre instanceof Pre
            && $Response->Body->raw === '<pre>{"status":"ok"}</pre>'
            && $Response->Resources->fetch('Raw') === null,
         description: 'Pre is a built-in response resource and Raw stays on Response::send'
      );

      try {
         $Response->Databse;
         $unknownResource = false;
      }
      catch (InvalidArgumentException) {
         $unknownResource = true;
      }

      yield assert(
         assertion: $unknownResource,
         description: 'Response::__get rejects unknown resources at the access site'
      );

      $lazyBuilds = 0;
      $lazyContext = null;
      $Response->Resources->load([
         'LazyDatabase' => function (Response $ResourceResponse) use (&$lazyBuilds, &$lazyContext, $SQL): Database {
            $lazyBuilds++;
            $lazyContext = $ResourceResponse;

            return new Database($SQL);
         },
      ]);

      $Lazy = $Response->LazyDatabase;
      $LazyAgain = $Response->LazyDatabase;
      $reset();
      $LazyAfterReset = $Response->LazyDatabase;
      $SQL->scopes = [];
      $LazyOperation = $LazyAfterReset instanceof Database
         ? $LazyAfterReset->query('WAIT')
         : null;
      $RequestScope = $SQL->scopes[0] ?? null;

      yield assert(
         assertion: $Lazy instanceof Database
            && $Lazy === $LazyAgain
            && $LazyAfterReset instanceof Database
            && $LazyAfterReset !== $Lazy
            && $LazyOperation?->finished === true
            && $RequestScope !== $Package
            && $RequestScope !== $Request
            && $lazyBuilds === 2
            && $lazyContext === $Response,
         description: 'Response resource definitions are lazy and use a per-request SQL stickiness scope'
      );

      $ORMRepository = $LazyAfterReset instanceof Database
         ? $LazyAfterReset->map(ResourceUser::class)
         : null;
      $ORMOperation = $ORMRepository?->find(1);

      yield assert(
         assertion: $ORMOperation instanceof Operation
            && $ORMRepository?->Awaiting === $LazyAfterReset
            && ($SQL->scopes[1] ?? null) === $RequestScope,
         description: 'Database::map creates ORM repositories with the per-request SQL stickiness scope and await bridge'
      );

      $Deferred = clone $Response;
      $DeferredLazy = $Deferred->LazyDatabase;
      $SQL->scopes = [];
      $DeferredOperation = $DeferredLazy instanceof Database
         ? $DeferredLazy->query('WAIT')
         : null;

      yield assert(
         assertion: $DeferredLazy instanceof Database
            && $DeferredOperation?->finished === true
            && ($SQL->scopes[0] ?? null) === $RequestScope,
         description: 'Forked response database resources keep the same per-request SQL stickiness scope'
      );

      $Scheduling = new RecordingSchedulingResource;
      $Response->mount($Scheduling);
      $invalid = false;

      try {
         $Scheduling->wait('invalid-wait-token');
      }
      catch (InvalidArgumentException $Exception) {
         $invalid = $Exception->getMessage() === 'HTTP response wait expects Readiness, resource or null.';
      }

      yield assert(
         assertion: $Scheduling->async && $invalid,
         description: 'Response scheduler bridge rejects invalid wait tokens'
      );

      $waits = 0;
      $Resource->schedule(function (mixed $value = null) use (&$waits): void {
         $waits++;
      });

      $Awaited = $Resource->await($SQL->query('WAIT'));

      yield assert(
         assertion: $Awaited->finished && $Awaited->Result?->row === ['value' => 42] && $waits === 1,
         description: 'Database::await waits one existing operation without Runner'
      );

      $waits = 0;
      $Operation = $Resource->query('WAIT');

      yield assert(
         assertion: $Operation->finished && $Operation->Result?->row === ['value' => 42] && $waits === 1,
         description: 'Database::query waits through the bound scheduler and returns the operation'
      );

      $Result = $Resource->fetch('SELECT 1');

      yield assert(
         assertion: $Result->row === ['value' => 42],
         description: 'Database::fetch returns the resolved result'
      );

      $SQL->advanced = [];
      $QueryResult = $Resource->fetch(new Query('SELECT $1::int AS value', [42]));
      $BuilderResult = $Resource->fetch(
         $Resource
            ->table(new Identifier('users'))
            ->select(new Identifier('id'))
      );

      yield assert(
         assertion: $QueryResult->row === ['value' => 42]
            && $BuilderResult->row === ['value' => 42]
            && $SQL->tables === ['"users"']
            && $SQL->advanced[0] === 'SELECT $1::int AS value'
            && str_contains($SQL->advanced[1], 'FROM "users"'),
         description: 'Database::table proxies Builder creation and fetch accepts Query and Builder inputs'
      );

      $failed = false;

      try {
         $Resource->fetch('FAIL');
      }
      catch (RuntimeException $RuntimeException) {
         $failed = $RuntimeException->getMessage() === 'expected failure';
      }

      yield assert(
         assertion: $failed,
         description: 'Database::fetch throws when the SQL operation fails'
      );

      $missingResult = false;

      try {
         $Resource->fetch('NO_RESULT');
      }
      catch (RuntimeException $RuntimeException) {
         $missingResult = $RuntimeException->getMessage() === 'SQL operation completed without a result.';
      }

      yield assert(
         assertion: $missingResult,
         description: 'Database::fetch throws when the SQL operation has no result'
      );

      $waits = 0;
      $Operations = [
         $SQL->query('WAIT'),
         $SQL->query('SELECT 2'),
      ];
      $Operations = $Resource->drain($Operations);

      yield assert(
         assertion: $Operations[0]->finished && $Operations[1]->finished && $waits === 1,
         description: 'Database::drain waits for multiple operations'
      );

      $value = $Resource->transact(function (Transaction $Transaction, Database $Database): string {
         $Database->await($Transaction->query('SELECT 3'));

         return 'done';
      });

      yield assert(
         assertion: $value === 'done' && $SQL->Transaction->events === ['query', 'commit'],
         description: 'Database::transact commits after successful work'
      );

      $SQL->Transaction = new RecordingTransaction;
      $rolledBack = false;

      try {
         $Resource->transact(function (): void {
            throw new RuntimeException('work failed');
         });
      }
      catch (RuntimeException) {
         $rolledBack = $SQL->Transaction->events === ['rollback'];
      }

      yield assert(
         assertion: $rolledBack,
         description: 'Database::transact rolls back when work throws'
      );

      $SQL->Transaction = new RecordingTransaction;
      $SQL->Transaction->rollbackThrows = true;
      $original = false;

      try {
         $Resource->transact(function (): void {
            throw new RuntimeException('work failed');
         });
      }
      catch (RuntimeException $RuntimeException) {
         $original = $RuntimeException->getMessage() === 'work failed'
            && $SQL->Transaction->events === ['rollback'];
      }

      yield assert(
         assertion: $original,
         description: 'Database::transact preserves the original error when rollback fails'
      );
   }
);
