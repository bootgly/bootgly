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
use function array_values;
use function http_build_query;
use function implode;
use function is_string;
use function max;
use function min;
use function spl_object_id;
use function strtok;
use BackedEnum;
use Closure;
use Fiber;
use InvalidArgumentException;
use RuntimeException;
use Stringable;
use Throwable;
use WeakReference;

use const Bootgly\WPI;
use Bootgly\ADI\Database\Operation\OperationStates;
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
use Bootgly\WPI\Events\Cancellation;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource\Scheduling;


/**
 * HTTP response resource for awaiting async SQL database operations.
 *
 * A wait that never comes back — refused by the reactor, interrupted, or a
 * request Fiber destroyed on client disconnect or eviction — withdraws what it
 * left in flight, so the pool takes the slot back at once. A refused or
 * interrupted wait does so inside a transaction too: its error reaches the
 * work, and withdrawing a statement on the reserved connection severs that
 * session. The one exception is the transaction a destroyed Fiber had open
 * inside `transact()` — in its work, its commit, or the rollback of a failed
 * work: it is not withdrawn, but adopted by a detached Fiber on the worker
 * reactor that drains it and rolls it back on its own session, which is
 * reset — advisory locks, LISTEN — before the next caller gets it.
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
   //   Held weakly: a strong reference from this resource — which the Fiber's
   //   own stack keeps alive — made a cycle, so a destroyed request Fiber was
   //   only unwound by a later GC run, its transaction teardown with it.
   /** @var null|WeakReference<Fiber<mixed,mixed,mixed,mixed>> */
   private null|WeakReference $Fiber = null;
   // ! The execution context known to be under destruction: one of its waits
   //   came back through its `finally` alone (see withdraw()). Code the work
   //   runs while it unwinds — a `finally` that queries, a destructor — can
   //   still throw into transact(), which must then leave the transaction to
   //   its adopter instead of awaiting a rollback nothing can drive. Held
   //   weakly, for the same reason as $Fiber.
   /** @var null|WeakReference<Fiber<mixed,mixed,mixed,mixed>> */
   private null|WeakReference $Destroyed = null;
   // ! The bound response once clean() let it go — its deferred job ended —
   //   held weakly: the response owns this instance, so the strong edge was a
   //   cycle only the collector could break. paginate() reads it from here.
   /** @var null|WeakReference<Response> */
   private null|WeakReference $Reference = null;


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
      $this->Reference = null;

      return $this;
   }

   /**
    * Let the bound response go when the deferred job it served ends.
    *
    * Reached from `Resources::release()` — or from `Resources::fetch()`, for
    * an instance built after it: this instance stays mounted and keeps
    * answering for the same response, held weakly from here on, for as long
    * as that response lives.
    */
   public function clean (): void
   {
      $Response = $this->Response;

      // ? Unbound, or already released
      if ($Response === null) {
         return;
      }

      $this->Reference = WeakReference::create($Response);
      $this->Response = null;
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

      if ($this->Transaction !== null && $this->Fiber?->get() === Fiber::getCurrent()) {
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
      // ? A response released at its job's end is read through the weak
      //   edge; once it is gone, the headers below have nowhere to land
      $Response = $this->Response ?? $this->Reference?->get();
      if ($Response === null && $this->Reference === null) {
         throw new RuntimeException('Database response resource is not bound.');
      }
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
         $Response?->Header->set('X-Total-Count', (string) $Pagination->total);

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
         $Response?->Header->set('Link', implode(', ', $links));
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

      if ($this->Transaction !== null && $this->Fiber?->get() === Fiber::getCurrent()) {
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
      $returned = false;
      $thrown = false;

      try {
         while ($Operation->finished === false) {
            $Operation = $this->Database->advance($Operation);

            if ($Operation->finished) {
               break;
            }

            $Wait ??= $this->Wait
               ?? throw new RuntimeException('Database response resource is not bound.');

            // ! A readiness park waits on "socket readable" as a proxy for
            //   "operation finished". A co-located sibling context resumed for
            //   the same socket can consume that readability AND finish this
            //   operation — arm the completion edge so whoever finishes it
            //   reschedules this Fiber (see wake()).
            $Readiness = $Operation->Readiness;
            $Waker = $Readiness === null ? null : $this->wake();

            if ($Waker !== null) {
               $Operation->Waker = $Waker;
            }

            $Wait($Readiness);

            // ! Disarmed before advancing: awake, this context observes its own
            //   completions — a self-wake would only queue a spurious resume.
            if ($Waker !== null) {
               $Operation->Waker = null;
            }
         }

         $returned = true;

         return $Operation;
      }
      catch (Throwable $Throwable) {
         $thrown = true;

         throw $Throwable;
      }
      finally {
         // ? The wait never came back: refused, interrupted, or the Fiber is
         //   being destroyed (only this block runs then) — nobody will advance
         //   the operation again, so its connection is taken back here. A
         //   destroyed Fiber leaves its open transaction's operation to the
         //   transact() frame that adopts it (see withdraw()).
         if ($returned === false) {
            $this->withdraw([$Operation], $thrown);
         }
      }
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
      $returned = false;
      $thrown = false;

      try {
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

            // ! A readiness park waits on "socket readable" as a proxy for
            //   "operations finished". A co-located sibling context resumed for
            //   the same socket can consume that readability AND finish the
            //   operations parked here — the socket then never signals again.
            //   Arm the completion edge so whoever finishes one of these
            //   operations reschedules this Fiber (see wake()).
            $Waker = $waiting === null ? null : $this->wake();

            if ($Waker !== null) {
               foreach ($Operations as $Operation) {
                  if ($Operation->finished === false) {
                     $Operation->Waker = $Waker;
                  }
               }
            }

            $Wait($waiting);

            // ! Disarmed before advancing: awake, this context observes its own
            //   completions — a self-wake would only queue a spurious resume.
            if ($Waker !== null) {
               foreach ($Operations as $Operation) {
                  if ($Operation->Waker !== null) {
                     $Operation->Waker = null;
                  }
               }
            }
         }

         $returned = true;

         return $Operations;
      }
      catch (Throwable $Throwable) {
         $thrown = true;

         throw $Throwable;
      }
      finally {
         // ? Same as await(): whatever the group still has in flight is
         //   withdrawn — but for a destroyed Fiber's open transaction.
         if ($returned === false) {
            $this->withdraw($Operations, $thrown);
         }
      }
   }

   /**
    * Execute work inside one SQL transaction.
    *
    * The whole unit of work is this call's: its BEGIN, the callback and the
    * commit of every level it left open, or the rollback of every level when
    * the work throws. A wait refused or interrupted inside it withdraws its
    * operation — on the reserved connection, which severs the session — and
    * its error reaches the work, as anywhere else. A request Fiber destroyed
    * anywhere inside it — a client disconnect, an eviction — does not sever
    * the transaction, even when the work's own unwinding throws or the
    * rollback of a failed work was in flight: a detached Fiber adopts it on
    * the worker reactor, drains what was still in flight on the reserved
    * connection and rolls the transaction back on its own session, and the
    * session is reset — advisory locks, LISTEN — before the connection is
    * lent again (see adopt()). Without a reactor it is withdrawn instead,
    * which severs the session so the server rolls it back.
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
      /** @var null|Fiber<mixed,mixed,mixed,mixed> $Current */
      $Current = Fiber::getCurrent();
      $Transaction = $this->Fiber?->get() === $Current ? $this->Transaction : null;
      // ! Only a weak handle crosses into the work: a strong local kept on the
      //   Fiber's own stack made it unreachable only through a GC run, and a
      //   destroyed request left its transaction open until then.
      $Context = $Current === null ? null : WeakReference::create($Current);
      unset($Current);

      if ($Transaction === null) {
         $entry = 0;
         $Transaction = $this->Database->begin();
         $Begin = $Transaction->Operation;
      }
      else {
         $entry = $Transaction->depth;
         $Begin = $Transaction->begin();
      }

      // ! For the length of the unit of work — its BEGIN, the callback and the
      //   commit — this resource's query surface IS the transaction. Through
      //   the facade the same call asks the pool for a DIFFERENT connection:
      //   with capacity to spare it runs outside the unit of work that is
      //   supposed to contain it — reading none of its writes, covered by none
      //   of its rollback — and with none to spare it parks waiting for the
      //   connection this very caller is holding. The surface also tells a
      //   destroyed Fiber's await() which operation to leave for adoption.
      //   Saved and restored, never cleared, so a nested transact() hands the
      //   outer surface back — and restored before this frame's rollback
      //   path: the outermost frame has settled by then, so its awaits are
      //   not adopted, while a nested frame's still belong to the outer one.
      $Previous = $this->Transaction;
      $Owner = $this->Fiber;
      $begun = false;
      $settled = false;
      $rolling = false;

      try {
         try {
            $this->Transaction = $Transaction;
            $this->Fiber = $Context;

            // @ Awaited inside the teardown region, so a Fiber destroyed while
            //   its BEGIN (or savepoint) is in flight is adopted too.
            if ($Begin !== null) {
               $this->await($Begin);
               $this->check($Begin);
            }

            $begun = true;

            $result = $work($Transaction, $this);

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
         }
         finally {
            $this->Transaction = $Previous;
            $this->Fiber = $Owner;
         }

         $settled = true;

         $this->Database->touch($this->Scope);

         return $result;
      }
      catch (Throwable $Throwable) {
         // ? The Fiber is being destroyed and the work's own unwinding threw —
         //   a `finally` that queries, a destructor. Nothing here can await a
         //   rollback, and composing one would discard the statement left in
         //   flight for the adopter, dropping its session outside the pool's
         //   accounting. Left unsettled, the finally below adopts it.
         $Current = Fiber::getCurrent();

         if ($Current !== null && $this->Destroyed?->get() === $Current) {
            throw $Throwable;
         }

         unset($Current);

         $settled = true;

         // ? A BEGIN that failed opened no level: there is nothing to unwind.
         if ($begun === false) {
            throw $Throwable;
         }

         // ! The outermost frame keeps the surface for its own teardown: a
         //   Fiber destroyed while the ROLLBACK is in flight leaves it to the
         //   adopter (see withdraw()), as during the work. A nested frame's
         //   rollbacks run on the outer surface, the same transaction, already.
         if ($entry === 0) {
            $this->Transaction = $Transaction;
            $this->Fiber = $Context;
            $rolling = true;
         }

         try {
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
         }
         finally {
            if ($entry === 0) {
               $this->Transaction = $Previous;
               $this->Fiber = $Owner;
            }
         }

         // ! Reached only when the loop ended: destroyed inside it, the
         //   rollback in flight is still the finally's to adopt.
         $rolling = false;

         throw $Throwable;
      }
      finally {
         // ? Neither committed nor rolled back: the Fiber is being destroyed
         //   (only this block runs then — a client disconnect, an eviction —
         //   or the work's unwinding threw while it was, see the catch), so
         //   nothing here will ever await a teardown. Destroyed while the
         //   catch's rollback was in flight counts the same: that rollback is
         //   the operation left in flight. The outermost frame hands
         //   the whole transaction, at any savepoint depth, to an adopter: the
         //   one operation the context left in flight on the reserved
         //   connection — its BEGIN, a statement, a savepoint, the final COMMIT
         //   — is drained there, then the transaction is rolled back on its
         //   own session unless that COMMIT already ended it. The session keeps
         //   its slot and the teardown's own completion releases the
         //   reservation. When nothing can adopt it, it is withdrawn as before:
         //   its ROLLBACK withdrawn before the wire severs the session, so the
         //   server rolls the work back and the pool reservation drops. A
         //   failure has nobody to report to: the dying Fiber must not unwind
         //   into handler code it cannot suspend in.
         // @phpstan-ignore-next-line (a destroyed Fiber skips the try and the catch)
         if ($entry === 0 && ($settled === false || $rolling)) {
            $Operation = $Transaction->Operation;
            $Pending = $Operation !== null && $Operation->finished === false ? [$Operation] : [];
            $adopted = false;

            try {
               $adopted = $this->adopt($Transaction, $Pending);
            }
            catch (Throwable) {
               // ? Withdrawn below
            }

            if ($adopted === false) {
               self::sever($this->Database, $Transaction, $Pending);
            }
         }
      }
   }

   /**
    * Withdraw what a wait that never came back leaves in flight.
    *
    * @param array<int,Operation> $Operations
    */
   private function withdraw (array $Operations, bool $thrown): void
   {
      // ! A destroyed Fiber (nothing thrown) leaves the operation its open
      //   transaction tracks in flight — its BEGIN, a statement, a savepoint
      //   or the final COMMIT: the outermost transact() frame adopts it (see
      //   adopt()), and withdrawing it here would sever the very session the
      //   adopter drains it on. Only this context's transaction qualifies, and
      //   only while transact() holds it as the surface: a refused or
      //   interrupted wait, a stranger context, or the outermost frame's
      //   rollback path after a failed work still withdraws.
      $Adoptable = null;

      if ($thrown === false) {
         /** @var null|Fiber<mixed,mixed,mixed,mixed> $Current */
         $Current = Fiber::getCurrent();

         // ! Only a destroyed Fiber's wait comes back with nothing thrown:
         //   recorded, so a throw from the rest of its unwinding is told apart
         //   from a failed work (see transact()).
         if ($Current !== null) {
            $this->Destroyed = WeakReference::create($Current);
         }

         if ($this->Transaction !== null && $this->Fiber?->get() === $Current) {
            $Adoptable = $this->Transaction->Operation;
         }

         unset($Current);
      }

      // @ Unfinished ones only, each once. An armed completion edge may fire
      //   as they fail: it only ever wakes this very context, which is running
      //   now, so wake() leaves it alone.
      $Unfinished = [];

      foreach ($Operations as $Operation) {
         if ($Operation->finished === false && $Operation !== $Adoptable) {
            $Unfinished[spl_object_id($Operation)] = $Operation;
         }
      }

      if ($Unfinished === []) {
         return;
      }

      // ? A destroyed Fiber has no exception in flight and nobody to report
      //   to: a failed withdrawal must not turn its unwinding into an error
      //   that runs handler code inside a Fiber that can no longer suspend.
      //   Under an exception it propagates, chained to the one in flight.
      if ($thrown === false) {
         try {
            $this->Database->withdraw(...array_values($Unfinished));
         }
         catch (Throwable) {
            // ? Dropped with the Fiber
         }

         return;
      }

      $this->Database->withdraw(...array_values($Unfinished));
   }

   /**
    * Adopt the transaction a destroyed request Fiber left open.
    *
    * Nothing on the reserved connection is withdrawn: a detached Fiber,
    * scheduled on the worker reactor the way deferred responses are, drives
    * every operation still in flight there to completion — results discarded
    * — and then, while the transaction is still open, its top-level
    * `ROLLBACK`. Their own completion releases the reservation (`COMMIT` and
    * `ROLLBACK` carry `unlock`), so the session survives and keeps its slot,
    * and the connection is never lent with the transaction open. The session
    * is tainted first (`Pool::taint()`): that release writes the session
    * reset — advisory locks, LISTEN — before the connection is idle again.
    * Bounded by each operation's own deadline: the pool fails an expired
    * one, which ends the adoption.
    *
    * Synchronous and never suspends the caller: the adopter yields once
    * before touching anything, so a scheduler that refuses it leaves nothing
    * half-adopted. It captures neither this resource nor the request, so the
    * destroyed context is not kept reachable through it.
    *
    * @param array<int,Operation> $Operations What the context left in flight
    *    on the transaction (await() and drain() leave it to this method).
    *
    * @return bool False when nothing can adopt the transaction — no worker
    *    reactor (synchronous usage), a BEGIN that never reached the server,
    *    or an adopter the reactor refused — and the caller withdraws it.
    */
   private function adopt (Transaction $Transaction, array $Operations): bool
   {
      // ? No worker reactor (synchronous usage): nothing would ever resume it.
      if (isSet(TCP_Server_CLI::$Event) === false) {
         return false;
      }

      foreach ($Operations as $Operation) {
         // ? A BEGIN that never reached the server opened nothing to end:
         //   withdrawing it is exact and frees its slot at once, where
         //   adopting it would borrow a session to open and close an empty
         //   transaction.
         if (
            $Operation->lock
            && $Operation->state !== OperationStates::Querying
            && $Operation->state !== OperationStates::Reading
         ) {
            return false;
         }
      }

      // ! Disarmed: a completion edge would only wake the destroyed context,
      //   and it holds that context's lease.
      foreach ($Operations as $Operation) {
         $Operation->Waker = null;
      }

      $Event = TCP_Server_CLI::$Event;
      $Database = $this->Database;
      // ! Shared with the adopter: set once the reactor took it, after which
      //   the adopter alone answers for the transaction.
      $admitted = false;

      $Adopter = new Fiber(static function () use ($Database, $Transaction, $Operations, &$admitted): void {
         $resumed = false;

         // ! Admission first: the adopter yields before touching anything, so
         //   the scheduler decides on work that has not started.
         try {
            Fiber::suspend();

            $resumed = true;
         }
         catch (Throwable) {
            // ? A rejection delivered at the admission point
         }
         finally {
            // ? Taken by the reactor, then dropped before it ever ran — a
            //   later rejection, or a reactor torn down (worker shutdown):
            //   nobody else is left to withdraw the transaction. Refused at
            //   admission, the caller withdraws it instead.
            // @phpstan-ignore booleanAnd.rightAlwaysFalse (written by adopt() through the reference)
            if ($resumed === false && $admitted) {
               self::sever($Database, $Transaction, $Operations);
            }
         }

         if ($resumed) {
            self::settle($Database, $Transaction, $Operations);
         }
      });

      try {
         $value = $Adopter->start();
      }
      catch (Throwable) {
         // ? This context cannot switch Fibers: nothing was started.
         return false;
      }

      // ! The adopted session may still carry state its owner left at the
      //   session level — advisory locks, a LISTEN — which no ROLLBACK ends.
      //   Tainted while the transaction still reserves it, before anything
      //   can release it: the release that unlocks it (the teardown's) then
      //   writes the session reset before the connection is idle, so the
      //   next caller drains the reset ahead of its own answer. Every
      //   fallback drops the session instead, and the taint with it.
      $Tracked = $Transaction->Operation;
      $Reserved = $Tracked?->Connection;

      if ($Tracked !== null && $Reserved?->Protocol !== null && $Reserved->Protocol === $Tracked->Protocol) {
         ($Tracked->Pool ?? $Database->Pool)->taint($Reserved);
      }

      $admitted = $Event->schedule($Adopter, $value);

      // :
      return $admitted;
   }

   /**
    * Drive an adopted transaction to its end, inside the adopter Fiber.
    *
    * @param array<int,Operation> $Operations
    */
   private static function settle (SQL $Database, Transaction $Transaction, array $Operations): void
   {
      $settled = false;

      try {
         // @@ What the destroyed context left in flight finishes first, its
         //    answer read and discarded: abort() discards an outstanding
         //    statement, and one abandoned on a reserved connection severs the
         //    session the ROLLBACK must ride.
         foreach ($Operations as $Operation) {
            self::drive($Database, $Operation);
         }

         // @ Then the transaction ends on its own session. abort() refuses —
         //   an operation already failed, so nothing is driven — once it is no
         //   longer open: a COMMIT that was in flight ended it, as did a BEGIN
         //   the server refused or a session that dropped meanwhile.
         $Rollback = $Transaction->abort();
         $Operations[] = $Rollback;

         self::drive($Database, $Rollback);

         $settled = true;
      }
      catch (Throwable) {
         // ? Withdrawn below: nothing may escape into the reactor loop.
      }
      finally {
         // ? Failed, refused a wait by the selector, or destroyed with the
         //   reactor (worker shutdown): what is still pending is withdrawn,
         //   as if it had never been adopted.
         if ($settled === false) {
            self::sever($Database, $Transaction, $Operations);
         }
      }
   }

   /**
    * Drive one adopted operation to completion, parking the adopter Fiber on
    * its readiness the way await() parks a request.
    */
   private static function drive (SQL $Database, Operation $Operation): void
   {
      // @@
      while ($Operation->finished === false) {
         $Operation = $Database->advance($Operation);

         if ($Operation->finished) {
            break;
         }

         // ! A null readiness parks tick-based: resumed every iteration. A
         //   wait the selector refuses (its descriptor budget) is delivered
         //   here as an exception and escapes to settle(), which withdraws the
         //   transaction: polling by ticks instead would spin the reactor for
         //   as long as the answer takes — without end under no deadline.
         Fiber::suspend($Operation->Readiness);
      }
   }

   /**
    * Withdraw an orphaned transaction the way it was before adoption existed:
    * what it left in flight first, then its top-level `ROLLBACK`, withdrawn
    * before the wire, which severs the session so the server rolls the work
    * back and the pool reservation drops.
    *
    * @param array<int,Operation> $Operations
    */
   private static function sever (SQL $Database, Transaction $Transaction, array $Operations): void
   {
      // ? Nobody to report to: it runs in the finally of a dying Fiber or of
      //   an adopter the reactor drives.
      $Unfinished = [];

      foreach ($Operations as $Operation) {
         if ($Operation->finished === false) {
            $Unfinished[spl_object_id($Operation)] = $Operation;
         }
      }

      try {
         if ($Unfinished !== []) {
            $Database->withdraw(...array_values($Unfinished));
         }
      }
      catch (Throwable) {
         // ? Dropped
      }

      try {
         $Database->withdraw($Transaction->abort());
      }
      catch (Throwable) {
         // ? Dropped
      }
   }

   /**
    * Build a completion hook that reschedules this context's parked Fiber.
    *
    * A readiness park waits on "socket readable" as a proxy for "operations
    * finished". Under co-located pipelining a sibling context resumed for the
    * same socket can consume that readability and finish this context's
    * operations — the socket then never signals again and the Fiber strands
    * until a deadline. The hook restores the missing completion edge: whoever
    * finishes an armed operation moves this Fiber to the reactor tick queue,
    * where its own re-scan observes the completion.
    */
   private function wake (): null|Closure
   {
      $Fiber = Fiber::getCurrent();

      // ? Not parked under the worker reactor (synchronous usage) — the wait
      //   bridge returns without suspending, so no completion edge is needed.
      if ($Fiber === null || isSet(TCP_Server_CLI::$Event) === false) {
         return null;
      }

      $Event = TCP_Server_CLI::$Event;

      // ! Weak: an armed operation may outlive an evicted generation, and its
      //   late completion must not retain — or wake — a Fiber the reactor
      //   already destroyed or rebound. The captured lease pins the exact
      //   generation this park belongs to.
      $Reference = WeakReference::create($Fiber);
      $Lease = Cancellation::fetch($Fiber);
      $fired = false;

      // : One wake per park: every armed sibling shares this closure, and the
      //   first completion is the whole edge — the resumed re-scan sees the rest.
      return static function () use ($Reference, $Lease, $Event, &$fired): void {
         if ($fired) {
            return;
         }

         $Fiber = $Reference->get();

         // ? Gone, running, or rebound to another generation — not ours to wake
         if (
            $Fiber === null
            || $Fiber->isSuspended() === false
            || Cancellation::fetch($Fiber) !== $Lease
         ) {
            return;
         }

         $fired = true;
         $Event->schedule($Fiber, null);
      };
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
