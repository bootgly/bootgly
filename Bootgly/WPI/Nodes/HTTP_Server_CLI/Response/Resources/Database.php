<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources;


use function array_key_exists;
use function http_build_query;
use function implode;
use function is_string;
use function max;
use function min;
use function strtok;
use BackedEnum;
use Closure;
use Fiber;
use InvalidArgumentException;
use RuntimeException;
use Stringable;
use Throwable;

use const Bootgly\WPI;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Awaiting;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\ADI\Databases\SQL\Repository;
use Bootgly\ADI\Databases\SQL\Repository\Pagination;
use Bootgly\ADI\Databases\SQL\Repository\Pagination\Modes;
use Bootgly\ADI\Databases\SQL\Repository\Selection;
use Bootgly\ADI\Databases\SQL\Transaction;
use Bootgly\API\Environment\Configs;
use Bootgly\API\Environment\Configs\Config;
use Bootgly\API\Environment\Configs\DatabaseConfig;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource\Scheduling;


/**
 * HTTP response resource for awaiting async SQL database operations.
 */
class Database extends Resource implements Awaiting, Scheduling
{
   // * Config
   public SQL $Database;
   /** Default page size when the request omits `limit`. */
   public static int $limit = 10;
   /** Maximum accepted request `limit` (client input clamp). */
   public static int $cap = 100;

   // * Data
   private null|Closure $Wait = null;
   // ! Bound by attach() on mount (the per-request HTTP path). Plain nullable —
   //   a get hook here costs ~2% CPU on 1-query routes (kills engine inlining),
   //   and a null scope falls through to the SQL facade's default resolution
   private null|object $Scope = null;
   private null|Response $Response = null;

   // * Metadata
   // ! The transaction that owns this resource's query surface while a
   //   transact() callback runs, and null the rest of the time. Plain nullable
   //   for the same reason $Scope is: a get hook on this class costs ~2% CPU on
   //   1-query routes, and null falls through to the pool facade at every site.
   private null|Transaction $Transaction = null;
   // ! The execution context that opened it. One resource object is reachable
   //   from two of them — Resources::fork() carries a definition-less mount into
   //   the clone by reference, and a plain capture crosses defer() with no
   //   registry at all — and a surface without an owner would hand a stranger's
   //   request the transaction: uncommitted rows to read, and its own writes
   //   destroyed by a rollback it never asked for.
   /** @var null|Fiber<mixed,mixed,mixed,mixed> */
   private null|Fiber $Fiber = null;


   public function __construct (SQL $Database)
   {
      parent::__construct();

      // * Config
      $this->Database = $Database;
   }

   /**
    * Provide a lazy factory that builds this resource from a `database` scope.
    *
    * Encapsulates the per-worker connection singleton, the response context
    * guard and the canonical config path (`Configs` → `DatabaseConfig` → `SQL`)
    * so projects register the resource in a single line.
    *
    * @return Closure(object):self
    */
   public static function provide (string $configs): Closure
   {
      return static function (object $Context) use ($configs): self {
         // ! Per-worker connection: pooled across requests on the same worker.
         //   The prototype spares constructor + guards per request — each
         //   request gets a cheap clone with fresh, unbound Wait/Scope.
         static $Database = null;
         static $Prototype = null;

         // ?
         if ($Context instanceof Response === false) {
            throw new RuntimeException('Database response resource expects a Response context.');
         }

         // ?: Hot path — worker already connected: clone the prototype
         if ($Prototype instanceof self) {
            return clone $Prototype;
         }

         // @ Build once per worker
         if ($Database instanceof SQL === false) {
            $Configs = new Configs($configs);
            $Configs->allow('database', [
               // # Connection
               'DB_CONNECTION',
               'DB_ENABLED',
               'DB_HOST',
               'DB_NAME',
               'DB_PASS',
               'DB_POOL_MAX',
               'DB_POOL_MIN',
               'DB_PORT',
               'DB_SSLCAFILE',
               'DB_SSLMODE',
               'DB_SSLPEER',
               'DB_SSLVERIFY',
               'DB_STATEMENTS',
               'DB_TIMEOUT',
               'DB_USER',
               // # Routing
               'DB_ROUTING_STICKY',
               // # Replica 1
               'DB_REPLICA_1_HOST',
               'DB_REPLICA_1_PORT',
               'DB_REPLICA_1_NAME',
               'DB_REPLICA_1_USER',
               'DB_REPLICA_1_PASS',
               'DB_REPLICA_1_TIMEOUT',
               'DB_REPLICA_1_STATEMENTS',
               'DB_REPLICA_1_SSLMODE',
               'DB_REPLICA_1_SSLVERIFY',
               'DB_REPLICA_1_SSLPEER',
               'DB_REPLICA_1_SSLCAFILE',
               'DB_REPLICA_1_POOL_MIN',
               'DB_REPLICA_1_POOL_MAX',
               // # Replica 2
               'DB_REPLICA_2_HOST',
               'DB_REPLICA_2_PORT',
               'DB_REPLICA_2_NAME',
               'DB_REPLICA_2_USER',
               'DB_REPLICA_2_PASS',
               'DB_REPLICA_2_TIMEOUT',
               'DB_REPLICA_2_STATEMENTS',
               'DB_REPLICA_2_SSLMODE',
               'DB_REPLICA_2_SSLVERIFY',
               'DB_REPLICA_2_SSLPEER',
               'DB_REPLICA_2_SSLCAFILE',
               'DB_REPLICA_2_POOL_MIN',
               'DB_REPLICA_2_POOL_MAX',
            ]);
            $Scope = $Configs->get('database');

            // @phpstan-ignore-next-line
            if ($Scope instanceof Config === false || $Scope->Enabled->get() !== true) {
               throw new RuntimeException('Enable DB_ENABLED=true in the database config scope and set DB_HOST, DB_PORT, DB_NAME, DB_USER and DB_PASS as needed.');
            }

            $Database = new SQL(new DatabaseConfig($Scope)->configure());
         }

         // :
         $Prototype = new self($Database);

         return clone $Prototype;
      };
   }

   /**
    * Bind the response context for request-driven helpers.
    */
   public function bind (Response $Response): static
   {
      $this->Response = $Response;

      return $this;
   }

   /**
    * Bind the logical read-after-write scope.
    */
   public function scope (object $Scope): static
   {
      $this->Scope = $Scope;

      return $this;
   }

   /**
    * Bind the response wait bridge.
    */
   public function schedule (Closure $Wait): static
   {
      $this->Wait = $Wait;

      return $this;
   }

   /**
    * Start a SQL query builder for one table through the wrapped database.
    */
   public function table (BackedEnum|Stringable|Builder|Query $Table, null|BackedEnum|Stringable $Alias = null): Builder
   {
      return $this->Database->table($Table, $Alias);
   }

   /**
    * Create one ORM repository through the wrapped database.
    *
    * @param class-string $Entity
    */
   public function map (string $Entity): Repository
   {
      // ? The transaction is this context's only while the context that opened it
      //   is the one asking. Anyone else falls through to the pool, which is
      //   what they would have got before a transaction was ever open.
      $Querying = $this->Database;

      if ($this->Transaction !== null && $this->Fiber === Fiber::getCurrent()) {
         $Querying = $this->Transaction;
      }

      return $Querying->map($Entity, $this->Scope, $this);
   }

   /**
    * Paginate one mapped entity through the request query parameters.
    *
    * Reads `page`, `limit` and `cursor` from the request query string
    * (a present `cursor` key selects keyset mode), emits the `X-Total-Count`
    * and `Link` REST headers and returns the negotiable body.
    *
    * Client-input contract: malformed cursors throw before any query is
    * dispatched — catch `InvalidArgumentException` in the route to answer
    * with a 400-class response.
    *
    * @param class-string|Repository $Entity
    * @return array<string,mixed>
    * @throws InvalidArgumentException When the request cursor is malformed.
    */
   public function paginate (string|Repository $Entity, null|Selection $Selection = null): array
   {
      // ?
      $Response = $this->Response
         ?? throw new RuntimeException('Database response resource is not bound.');
      $Request = WPI->Request;

      // ! Client input: clamped limit and page, cursor presence wins.
      $limit = (int) $Request->query('limit', (string) static::$limit);
      $limit = $limit < 1 ? static::$limit : min($limit, static::$cap);
      $page = max(1, (int) $Request->query('page', '1'));
      $cursor = $Request->query('cursor');

      $Pagination = array_key_exists('cursor', $Request->queries)
         ? new Pagination(limit: $limit, cursor: $cursor === '' ? null : $cursor, Mode: Modes::Cursor)
         : new Pagination(limit: $limit, page: $page);

      // @ Paginated fetch awaited through this resource bridge.
      $Repository = is_string($Entity) ? $this->map($Entity) : $Entity;
      $Operation = $this->await($Repository->paginate($Selection, $Pagination, $this->Scope));
      $this->check($Operation);
      $Mapped = $Repository->hydrate($Operation);

      // @ REST pagination headers and negotiable body.
      $links = [];

      if ($Pagination->Mode === Modes::Cursor) {
         if ($Pagination->next !== null) {
            $links[] = $this->link($Request, ['cursor' => $Pagination->next, 'limit' => $limit], 'next');
         }

         $body = [
            'items' => $Mapped->entities,
            'limit' => $limit,
            'next' => $Pagination->next,
         ];
      }
      else {
         $Response->Header->set('X-Total-Count', (string) $Pagination->total);

         if ($page < ($Pagination->pages ?? 0)) {
            $links[] = $this->link($Request, ['page' => $page + 1, 'limit' => $limit], 'next');
         }
         if ($page > 1) {
            $links[] = $this->link($Request, ['page' => $page - 1, 'limit' => $limit], 'prev');
         }

         $body = [
            'items' => $Mapped->entities,
            'page' => $page,
            'pages' => $Pagination->pages,
            'limit' => $limit,
            'total' => $Pagination->total,
         ];
      }

      if ($links !== []) {
         $Response->Header->set('Link', implode(', ', $links));
      }

      // : Negotiable pagination body.
      return $body;
   }

   /**
    * Create and await one SQL operation.
    *
    * @param string|Builder|Query $query
    * @param array<int|string,mixed> $parameters
    */
   public function query (string|Builder|Query $query, array $parameters = [], null|object $Scope = null): Operation
   {
      // ? The transaction is this context's only while the context that opened it
      //   is the one asking. Anyone else falls through to the pool, which is
      //   what they would have got before a transaction was ever open.
      $Querying = $this->Database;

      if ($this->Transaction !== null && $this->Fiber === Fiber::getCurrent()) {
         $Querying = $this->Transaction;
      }

      return $this->await($Querying->query($query, $parameters, $Scope ?? $this->Scope));
   }

   /**
    * Create and await one SQL operation, throwing when it fails.
    *
    * @param string|Builder|Query $query
    * @param array<int|string,mixed> $parameters
    */
   public function fetch (string|Builder|Query $query, array $parameters = [], null|object $Scope = null): Result
   {
      $Operation = $this->query($query, $parameters, $Scope);
      $this->check($Operation);

      $Result = $Operation->Result;

      if ($Result === null) {
         throw new RuntimeException('SQL operation completed without a result.');
      }

      return $Result;
   }

   /**
    * Await one SQL operation through the bound response scheduler.
    */
   public function await (Operation $Operation): Operation
   {
      // ! Hoisted out of the loop — the binding cannot change mid-await
      $Wait = null;

      while ($Operation->finished === false) {
         $Operation = $this->Database->advance($Operation);

         if ($Operation->finished) {
            break;
         }

         $Wait ??= $this->Wait
            ?? throw new RuntimeException('Database response resource is not bound.');

         $Wait($Operation->Readiness);
      }

      return $Operation;
   }

   /**
    * Await a group of SQL operations through the bound response scheduler.
    *
    * @param array<int,Operation> $Operations
    * @return array<int,Operation>
    */
   public function drain (array $Operations): array
   {
      // ! Hoisted out of the loop — the binding cannot change mid-drain
      $Wait = null;

      while (true) {
         foreach ($Operations as $id => $Operation) {
            if ($Operation->finished) {
               continue;
            }

            $Operations[$id] = $this->Database->advance($Operation);
         }

         // ! Re-scan AFTER all advances: co-located operations share a
         //   connection, so advancing a later sibling may have finished
         //   operations already counted as pending — parking on that stale
         //   snapshot would suspend the Fiber with nothing left in flight.
         $waiting = null;
         $pending = false;

         foreach ($Operations as $Operation) {
            if ($Operation->finished === false) {
               $pending = true;
               $waiting ??= $Operation->Readiness;
            }
         }

         if ($pending === false) {
            break;
         }

         $Wait ??= $this->Wait
            ?? throw new RuntimeException('Database response resource is not bound.');

         $Wait($waiting);
      }

      return $Operations;
   }

   /**
    * Execute work inside one SQL transaction.
    *
    * @param callable(Transaction,self):mixed $work
    */
   public function transact (callable $work): mixed
   {
      // ! A nested transact() nests on the surface already open, as a savepoint.
      //   Beginning again on the facade would open a SECOND, independent
      //   transaction: its writes escape the outer one's rollback, and on a
      //   saturated pool its exclusive BEGIN parks waiting for the connection the
      //   outer one is holding. `$entry` is the depth this call found, so the
      //   unwind below gives back exactly the levels this call opened.
      $Fiber = Fiber::getCurrent();
      $Transaction = $this->Fiber === $Fiber ? $this->Transaction : null;

      if ($Transaction === null) {
         $entry = 0;
         $Transaction = $this->Database->begin();
         $Begin = $Transaction->Operation;
      }
      else {
         $entry = $Transaction->depth;
         $Begin = $Transaction->begin();
      }

      if ($Begin !== null) {
         $this->await($Begin);
         $this->check($Begin);
      }

      // ! For the length of the callback, this resource's query surface IS the
      //   transaction. Through the facade the same call asks the pool for a
      //   DIFFERENT connection: with capacity to spare it runs outside the unit
      //   of work that is supposed to contain it — reading none of its writes,
      //   covered by none of its rollback — and with none to spare it parks
      //   waiting for the connection this very caller is holding.
      //   Saved and restored, never cleared, so a nested transact() hands the
      //   outer surface back.
      $Previous = $this->Transaction;
      $Owner = $this->Fiber;

      try {
         try {
            $this->Transaction = $Transaction;
            $this->Fiber = $Fiber;

            $result = $work($Transaction, $this);
         }
         finally {
            $this->Transaction = $Previous;
            $this->Fiber = $Owner;
         }

         // @@ The work may have opened nested levels and left them open. One
         //    commit() at `depth > 1` releases a savepoint, so the outer
         //    transaction would never be committed and its pinned connection
         //    never handed back — while this method reported the unit of work
         //    as done. The contract here is the whole transaction, not the
         //    level the callback happened to stop on.
         do {
            // ? A `do` runs once even when the callback already unwound past the
            //   depth this call found — and at `depth === $entry === 1` commit()
            //   is no longer a savepoint release: it ends the CALLER's
            //   transaction, from inside a nested call, and the caller's own
            //   rollback then has nothing left to reach. The `$entry > 0` half
            //   keeps the outer call attempting its teardown unconditionally,
            //   which is what a transaction reporting `depth === 0` needs.
            if ($entry > 0 && $Transaction->depth <= $entry) {
               break;
            }

            $Commit = $this->await($Transaction->commit());

            // ? Every commit() that cannot make progress fails the operation,
            //   so the loop always ends through here rather than spinning.
            $this->check($Commit);
         }
         while ($Transaction->depth > $entry);

         $this->Database->touch($this->Scope);

         return $result;
      }
      catch (Throwable $Throwable) {
         // @@ Same for the failure path — a single rollback() unwinds one
         //    savepoint and leaves the transaction open. Every level is
         //    attempted: only the outermost carries `unlock`, so stopping at
         //    the first failure would strand the reservation.
         do {
            // ? A `do` runs once even when the callback already unwound past the
            //   depth this call found — and at `depth === $entry === 1` commit()
            //   is no longer a savepoint release: it ends the CALLER's
            //   transaction, from inside a nested call, and the caller's own
            //   rollback then has nothing left to reach. The `$entry > 0` half
            //   keeps the outer call attempting its teardown unconditionally,
            //   which is what a transaction reporting `depth === 0` needs.
            if ($entry > 0 && $Transaction->depth <= $entry) {
               break;
            }

            $depth = $Transaction->depth;

            try {
               $this->await($Transaction->rollback());
            }
            catch (Throwable) {
               // Preserve the original work failure.
            }

            // ? await() does not raise here and the catch above swallows what
            //   would, so a teardown that cannot advance is what ends this loop.
            if ($Transaction->depth >= $depth) {
               break;
            }
         }
         while ($Transaction->depth > $entry);

         throw $Throwable;
      }
   }

   /**
    * Render one Link header entry preserving foreign query parameters.
    *
    * @param array<string,int|string> $overrides
    */
   private function link (Request $Request, array $overrides, string $relation): string
   {
      $path = strtok($Request->URI, '?');
      $path = $path === false ? '/' : $path;

      // ! Mode keys are replaced, foreign query parameters are preserved.
      $queries = $Request->queries;
      unset($queries['page'], $queries['cursor']);
      $query = http_build_query([...$queries, ...$overrides]);

      // : Link header entry.
      return "<{$path}?{$query}>; rel=\"{$relation}\"";
   }

   /**
    * Check one awaited operation for failure.
    */
   private function check (Operation $Operation): void
   {
      if ($Operation->error !== null) {
         throw new RuntimeException($Operation->error);
      }
   }
}
