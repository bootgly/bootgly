<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases;


use function count;
use function is_string;
use function microtime;
use function str_starts_with;
use function trim;
use BackedEnum;
use Closure;
use Fiber;
use RuntimeException;
use Stringable;
use Throwable;
use WeakMap;
use WeakReference;

use Bootgly\ADI\Database;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Pool;
use Bootgly\ADI\Databases\SQL\Awaiting;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Dialect;
use Bootgly\ADI\Databases\SQL\Builder\Dialects;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers;
use Bootgly\ADI\Databases\SQL\Models;
use Bootgly\ADI\Databases\SQL\Normalized;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\ADI\Databases\SQL\Querying;
use Bootgly\ADI\Databases\SQL\Repository;
use Bootgly\ADI\Databases\SQL\Schema;
use Bootgly\ADI\Databases\SQL\Transaction;


/**
 * SQL database facade.
 */
class SQL extends Database implements Awaiting, Querying
{
   // * Config
   public Config $SQLConfig;
   public private(set) Dialect $Dialect;
   public private(set) Models $Models;
   // ! Pooled connections accept pipelined operations.
   public protected(set) bool $pipelining = true;

   // * Data
   /** @var array<int,Pool> */
   public array $ReplicaPools = [];

   // * Metadata
   private null|Schema $Schema = null;
   private int $replica = 0;
   /** @var WeakMap<object,float> */
   private WeakMap $Written;
   // ! The transaction each context has open through transact(), for the
   //   length of that call — what a nested call nests on.
   /** @var WeakMap<object,Transaction> */
   private WeakMap $Transactions;
   // ...


   /**
    * Create a SQL database facade.
    *
    * @param array<string,mixed>|Config $config
    */
   public function __construct (array|Config $config = [])
   {
      // * Config
      $Config = $config instanceof Config
         ? $config
         : new Config($config);
      $this->SQLConfig = $Config;
      $this->Written = new WeakMap;
      $this->Transactions = new WeakMap;
      $this->Models = new Models;

      $Dialects = new Dialects;
      $this->Dialect = $Dialects->fetch($Config->driver);

      parent::__construct($Config, Drivers::class);

      foreach ($Config->replicas as $replica) {
         $ReplicaConfig = new Config($replica);
         $this->ReplicaPools[] = new Pool($ReplicaConfig, new Connection($ReplicaConfig), Drivers::class);
      }
   }

   /**
    * Create an async SQL query operation.
    *
    * @param string|Builder|Query $query
    * @param array<int|string,mixed> $parameters
    */
   public function query (string|Builder|Query $query, array $parameters = [], null|object $Scope = null): Operation
   {
      if (is_string($query) && $this->ReplicaPools === []) {
         if (trim($query) === '') {
            throw new \InvalidArgumentException('SQL cannot be empty.');
         }

         $Operation = new Operation(null, $query, $parameters, $this->Pool->Config->timeout);
         $this->Pool->assign($Operation);

         return $Operation;
      }

      $Normalized = new Normalized($query, $parameters);
      $Scope = $this->resolve($Scope);
      $Pool = $this->route($Normalized, $Scope);
      $Operation = new Operation(null, $Normalized->SQL, $Normalized->parameters, $Pool->Config->timeout);

      if ($Pool !== $this->Pool && $Normalized->reading) {
         $Operation->FallbackPool = $this->Pool;
      }

      $Pool->assign($Operation);

      if ($Normalized->reading === false) {
         $this->touch($Scope);
      }

      return $Operation;
   }

   /**
    * Mark one logical scope as having written recently.
    */
   public function touch (null|object $Scope = null): void
   {
      $Scope = $this->resolve($Scope);
      $this->Written[$Scope] = microtime(true);
   }

   /**
    * Start a SQL query builder for one table.
    */
   public function table (BackedEnum|Stringable|Builder|Query $Table, null|BackedEnum|Stringable $Alias = null): Builder
   {
      $Builder = new Builder($this->Dialect);

      return $Builder->table($Table, $Alias);
   }

   /**
    * Create one ORM repository context for a mapped entity class.
    *
    * The await bridge is NOT defaulted to this connection: a raw `SQL` repository
    * stays in explicit/deferred relation mode unless the caller opts into eager/lazy
    * by passing an `Awaiting` bridge (e.g. the WPI `Response\Resources\Database`
    * resource, which passes itself). `Transaction::map()` defaults to the
    * transaction because a transaction is already an active awaited context.
    *
    * @param class-string $Entity
    */
   public function map (string $Entity, null|object $Scope = null, null|Awaiting $Awaiting = null): Repository
   {
      return Repository::create(
         $this,
         $this->Dialect,
         $this->Models,
         $Entity,
         $Scope,
         $Awaiting
      );
   }

   /**
    * Start the SQL schema structure builder.
    */
   public function structure (): Schema
   {
      if ($this->Schema === null) {
         $this->Schema = new Schema($this->Dialect);
      }

      return $this->Schema;
   }

   /**
    * Begin a SQL transaction pinned to one pooled connection.
    */
   public function begin (): Transaction
   {
      return new Transaction($this);
   }

   /**
    * Execute one unit of work inside a SQL transaction, blocking until it ends.
    *
    * For blocking contexts — commands, jobs, scripts. The whole unit of work
    * is this call's: it awaits the `BEGIN`, runs `$Work($Transaction)`,
    * commits every level the work left open and returns what the work
    * returned. Any throwable rolls the transaction back, level by level, and
    * is rethrown; a `BEGIN` or a `COMMIT` that fails is thrown too, never
    * reported as a success. On every way out the transaction is ended and its
    * connection is given back to the pool. Once the outermost `COMMIT`
    * succeeded, the default read-after-write scope is marked again, so the
    * sticky window counts from the commit.
    *
    * A call made inside the work, in the same context, nests on the open
    * transaction as a savepoint: its failure rolls back that level alone and
    * leaves the outer unit of work to its caller. A level that can be
    * neither rolled back nor released ends the whole transaction instead.
    *
    * Inside a Fiber it blocks the worker until the transaction ends: routes
    * use `$Response->Database->transact()`.
    *
    * @param Closure(Transaction):mixed $Work
    *
    * @throws Throwable Whatever the work threw, or the failure of the `BEGIN` or of a `COMMIT`.
    */
   public function transact (Closure $Work): mixed
   {
      // ! A nested transact() nests on the transaction this context already
      //   opened here, as a savepoint. Beginning again on the facade would
      //   open a SECOND, independent transaction: its writes escape the outer
      //   rollback, and on a saturated pool its exclusive BEGIN finds no
      //   capacity. Keyed by context, so a Fiber that suspends inside its work
      //   never lends its transaction to another one. Only a weak handle
      //   stays in this frame: a strong one on a Fiber's own stack would keep
      //   a destroyed Fiber alive until a GC run.
      $Context = Fiber::getCurrent() ?? $this;
      $Reference = WeakReference::create($Context);
      $Transaction = $this->Transactions[$Context] ?? null;
      $nested = $Transaction !== null;
      $entry = 0;

      if ($Transaction === null) {
         $Transaction = $this->begin();
         $this->Transactions[$Context] = $Transaction;
         $Begin = $Transaction->Operation;
      }
      else {
         // ? The outer unit of work has already ended — a nested level that
         //   could not be rolled back took the whole transaction with it.
         //   begin() would reopen it as a new one, which the outer call would
         //   neither commit nor roll back.
         if ($Transaction->depth <= 0) {
            throw new RuntimeException('SQL transaction is not active.');
         }

         // ! The depth this call found: the unwind below gives back exactly
         //   the levels opened from here on.
         $entry = $Transaction->depth;
         $Begin = $Transaction->begin();
      }

      unset($Context);

      $begun = false;
      $settled = false;

      try {
         // @ Await the BEGIN (or the savepoint): await() throws its failure.
         if ($Begin !== null) {
            $this->await($Begin);
         }

         $begun = true;

         $result = $Work($Transaction);

         // @@ The work may have opened nested levels and left them open. One
         //    commit() at `depth > 1` releases a savepoint only: the unit of
         //    work here is the whole transaction, not the level the work
         //    stopped on. A commit() that cannot make progress fails its
         //    operation, which await() throws — the loop never spins.
         do {
            // ? A nested call whose work already unwound to the depth it found
            //   has nothing left of its own: one more commit() would end the
            //   CALLER's level from inside the nested call.
            if ($nested && $Transaction->depth <= $entry) {
               // ? ...unless the close the work sent for it failed and the
               //   work kept that to itself: the level is in doubt, and
               //   returning would let the caller commit over it.
               if ($this->suspect($Transaction, $entry)) {
                  throw new RuntimeException(
                     $Transaction->Operation->error ?? 'SQL transaction operation is still active.'
                  );
               }

               break;
            }

            $this->await($Transaction->commit());
         }
         while ($Transaction->depth > $entry);

         $settled = true;
      }
      catch (Throwable $Throwable) {
         // ? A nested level whose close — the release composed above, or a
         //   rollback or a release the work sent itself — failed: no
         //   rollback() can name that level any more. Only for a call that
         //   began: one refused at its savepoint composed nothing, and a
         //   close still in flight then is its caller's.
         $stuck = $nested && $begun && $this->suspect($Transaction, $entry);

         $this->unwind($Transaction, $entry, $begun, $stuck);

         $settled = true;

         throw $Throwable;
      }
      finally {
         // ? Neither committed nor unwound: the Fiber this call runs in is
         //   being destroyed (only this block runs then), so nothing here may
         //   wait. The outermost frame ends the transaction without one: what
         //   is in flight is withdrawn and so is the ROLLBACK, before the
         //   wire, which severs the session — the server rolls the work back
         //   and the pool reservation drops. A nested frame leaves it to the
         //   outermost one, whose own `finally` runs next.
         // @phpstan-ignore-next-line (a destroyed Fiber skips the try's end and the catch)
         if ($settled === false && $nested === false) {
            $this->end($Transaction, false);
         }

         if ($nested === false) {
            $Context = $Reference->get();

            if ($Context !== null) {
               unset($this->Transactions[$Context]);
            }
         }
      }

      // @ Transaction::query() marks its scope when a write is composed; the
      //   replicas only see that write once the unit of work has committed.
      if ($nested === false) {
         $this->touch();
      }

      return $result;
   }

   /**
    * Advance an async SQL operation through the selected driver.
    */
   public function advance (Operation $Operation): Operation
   {
      $Pool = $Operation->Pool ?? $this->Pool;
      $Pool->advance($Operation);

      return $Operation;
   }

   /**
    * Await one SQL operation through the owning pool.
    */
   public function await (Operation $Operation): Operation
   {
      $Pool = $Operation->Pool ?? $this->Pool;
      $Pool->wait($Operation);

      return $Operation;
   }

   /**
    * Cancel one running SQL operation when supported by the driver.
    */
   public function cancel (Operation $Operation): Operation
   {
      $Pool = $Operation->Pool ?? $this->Pool;
      $Pool->cancel($Operation);

      return $Operation;
   }

   /**
    * Tell whether the close of a nested level is in doubt: the depth is back
    * at `$entry`, and the last operation the transaction composed — a
    * refusal composes none — is a `ROLLBACK TO SAVEPOINT` or a
    * `RELEASE SAVEPOINT` that failed or never finished. `Transaction` lowers
    * its depth when it composes a close, so the server may still hold a
    * level the depth no longer counts.
    */
   private function suspect (Transaction $Transaction, int $entry): bool
   {
      $Operation = $Transaction->Operation;

      // ?: Above the entry depth the level is still there to roll back
      if ($Transaction->depth > $entry || $Operation === null) {
         return false;
      }

      // ?: A close that the server confirmed left nothing behind
      if ($Operation->finished && $Operation->error === null) {
         return false;
      }

      // :
      return str_starts_with($Operation->SQL, 'ROLLBACK TO SAVEPOINT ')
         || str_starts_with($Operation->SQL, 'RELEASE SAVEPOINT ');
   }

   /**
    * Unwind the levels a failed unit of work left open above `$entry`, one
    * rollback per level so `Rollback` listeners hear each of them, and end
    * the whole transaction when a level cannot be rolled back — `$stuck`
    * says one already could not be closed.
    */
   private function unwind (Transaction $Transaction, int $entry, bool $begun, bool $stuck): void
   {
      // @@ Level by level — only when the BEGIN (or the savepoint) of this
      //    call was confirmed: one that failed opened nothing, and one whose
      //    wait threw is still in flight, which no rollback() may follow.
      while ($begun && $Transaction->depth > $entry) {
         $depth = $Transaction->depth;
         $rolled = false;

         try {
            $this->await($Transaction->rollback());

            $rolled = $Transaction->depth < $depth;
         }
         catch (Throwable) {
            // ? The failure of the work is the one its caller gets.
         }

         // ? A rollback() that threw (a listener), was refused (a statement
         //   left in flight at a nested level), failed or made no progress:
         //   trying the next level would leave this one open on the server.
         if ($rolled === false) {
            $stuck = true;

            break;
         }
      }

      // ? A level that could not be closed — a rollback the server refused
      //   has already lowered the depth, so the depth alone does not tell —
      //   or an operation this call began is still in flight (a commit or a
      //   rollback whose wait threw, a statement the work never awaited):
      //   the levels cannot be told apart any more, so the whole transaction
      //   ends — the outer work must not commit what this level may still
      //   hold, and in a blocking process nothing else would ever give the
      //   connection back. What a call that never began leaves in flight
      //   belongs to its caller.
      $Operation = $Transaction->Operation;
      $flying = $begun && $Operation !== null && $Operation->finished === false;

      if ($stuck || $Transaction->depth > $entry || $flying) {
         $this->end($Transaction, true);
      }
   }

   /**
    * End a whole transaction that can no longer be unwound level by level:
    * abort it, then withdraw whatever it still tracks in flight.
    */
   private function end (Transaction $Transaction, bool $awaiting): void
   {
      // @ abort() discards the outstanding statement and composes the
      //   top-level ROLLBACK; it refuses, with an operation already failed,
      //   a transaction that is no longer open.
      try {
         $Rollback = $Transaction->abort();

         if ($awaiting && $Rollback->finished === false) {
            $this->await($Rollback);
         }
      }
      catch (Throwable) {
         // ? Withdrawn below.
      }

      // @ A teardown that did not finish — never awaited, or its wait threw —
      //   is withdrawn before the wire is trusted again: the pool severs the
      //   session it rode, so the server ends the transaction and the
      //   reservation drops with it.
      $Operation = $Transaction->Operation;

      if ($Operation !== null && $Operation->finished === false) {
         try {
            $this->withdraw($Operation);
         }
         catch (Throwable) {
            // ? Nobody to report to: the caller gets the failure of the work.
         }
      }
   }

   /**
    * Route one normalized SQL operation to primary or a read replica.
    */
   private function route (Normalized $Normalized, object $Scope): Pool
   {
      if ($Normalized->reading === false || $this->ReplicaPools === []) {
         return $this->Pool;
      }

      $sticky = $this->SQLConfig->routing['sticky'];

      if ($this->check($Scope, $sticky)) {
         return $this->Pool;
      }

      $count = count($this->ReplicaPools);

      for ($attempt = 0; $attempt < $count; $attempt++) {
         // @ Cooperative event loop mutates this counter without preemption.
         $Pool = $this->ReplicaPools[$this->replica % $count];
         $this->replica = ($this->replica + 1) % $count;

         if ($Pool->healthy) {
            return $Pool;
         }
      }

      return $this->Pool;
   }

   /**
    * Resolve the logical read-after-write scope.
    */
   private function resolve (null|object $Scope): object
   {
      return $Scope ?? Fiber::getCurrent() ?? $this;
   }

   /**
    * Check whether one logical scope is still inside the sticky window.
    */
   private function check (object $Scope, float $sticky): bool
   {
      if ($sticky <= 0.0 || isset($this->Written[$Scope]) === false) {
         return false;
      }

      return microtime(true) - $this->Written[$Scope] < $sticky;
   }
}
