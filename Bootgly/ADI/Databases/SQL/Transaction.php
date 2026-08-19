<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL;


use function array_pop;
use function array_search;
use BackedEnum;
use Stringable;

use Bootgly\ABI\Events\Emitter;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Databases\SQL as SQLDatabase;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Repository;
use Bootgly\ADI\Databases\SQL\Transaction\Events;


/**
 * SQL transaction pinned to one pooled database connection.
 */
class Transaction implements Awaiting, Querying
{
   // * Config
   public SQLDatabase $Database;
   // ! Serial surface: one pending operation at a time.
   public protected(set) bool $pipelining = false;

   // * Data
   public private(set) null|Connection $Connection = null;
   public private(set) null|Operation $Operation = null;
   public private(set) int $depth = 0;

   // * Metadata
   /** @var array<int,string> */
   private array $savepoints = [];
   private int $saves = 0;


   /**
    * Start a database transaction immediately.
    */
   public function __construct (SQLDatabase $Database)
   {
      // * Config
      $this->Database = $Database;

      // * Data
      $this->Operation = $this->create('BEGIN', lock: true);
      $this->depth = 1;
   }

   /**
    * Start a nested transaction as a savepoint.
    */
   public function begin (): Operation
   {
      if ($this->ready() === false) {
         return $this->fail('BEGIN', [], 'SQL transaction operation is still active.');
      }

      // @ Events — transaction begin (guarded: zero-alloc when no listeners)
      $Emitter = Emitter::$Instance;
      $Emitter->check(Events::Begin) && $Emitter->emit(Events::Begin, $this);

      if ($this->depth <= 0) {
         $this->Operation = $this->create('BEGIN', lock: true);
         $this->depth = 1;

         return $this->Operation;
      }

      return $this->save();
   }

   /**
    * Run one SQL statement inside the transaction connection.
    *
    * @param string|Builder|Query $query
    * @param array<int|string,mixed> $parameters
    */
   public function query (string|Builder|Query $query, array $parameters = [], null|object $Scope = null): Operation
   {
      $Normalized = new Normalized($query, $parameters);
      $sql = $Normalized->SQL;
      $parameters = $Normalized->parameters;

      if ($this->ready() === false) {
         return $this->fail($sql, $parameters, 'SQL transaction operation is still active.');
      }

      if ($this->active() === false) {
         return $this->fail($sql, $parameters, 'SQL transaction is not active.');
      }

      if ($Normalized->reading === false) {
         $this->Database->touch($Scope);
      }

      return $this->create($sql, $parameters);
   }

   /**
    * Start a SQL query builder for one transaction table.
    */
   public function table (BackedEnum|Stringable|Builder|Query $Table, null|BackedEnum|Stringable $Alias = null): Builder
   {
      $Builder = new Builder($this->Database->Dialect);

      return $Builder->table($Table, $Alias);
   }

   /**
    * Create one ORM repository context for this transaction.
    *
    * @param class-string $Entity
    */
   public function map (string $Entity, null|object $Scope = null, null|Awaiting $Awaiting = null): Repository
   {
      return Repository::create(
         $this,
         $this->Database->Dialect,
         $this->Database->Models,
         $Entity,
         $Scope,
         $Awaiting ?? $this
      );
   }

   /**
    * Await one transaction SQL operation through the owning database.
    */
   public function await (Operation $Operation): Operation
   {
      return $this->Database->await($Operation);
   }

   /**
    * Commit the current transaction or release the current savepoint.
    */
   public function commit (): Operation
   {
      if ($this->ready() === false) {
         return $this->fail('COMMIT', [], 'SQL transaction operation is still active.');
      }

      if ($this->active() === false) {
         return $this->fail('COMMIT', [], 'SQL transaction is not active.');
      }

      // @ Events — transaction commit (guarded: zero-alloc when no listeners)
      $Emitter = Emitter::$Instance;
      $Emitter->check(Events::Commit) && $Emitter->emit(Events::Commit, $this);

      if ($this->depth > 1) {
         return $this->release();
      }

      $Operation = $this->create('COMMIT', unlock: true);
      $this->depth = 0;
      $this->savepoints = [];
      $this->Connection = null;

      return $Operation;
   }

   /**
    * Roll back the current transaction or savepoint.
    */
   public function rollback (null|string $name = null): Operation
   {
      $savepointing = $this->depth > 1 || $name !== null;

      // ? Only the top-level teardown may overtake an outstanding statement. A
      //   savepoint rollback is an ordinary statement on the serial surface, but
      //   a transaction that refuses to roll back strands its pool reservation
      //   and leaves the server session open inside the transaction.
      if ($savepointing && $this->ready() === false) {
         return $this->fail('ROLLBACK', [], 'SQL transaction operation is still active.');
      }

      if ($this->active() === false) {
         return $this->fail('ROLLBACK', [], 'SQL transaction is not active.');
      }

      // @ Events — transaction rollback (guarded: zero-alloc when no listeners)
      $Emitter = Emitter::$Instance;
      $Emitter->check(Events::Rollback) && $Emitter->emit(Events::Rollback, $this);

      if ($savepointing) {
         $index = $this->locate($name);

         // ? Either the stack is empty, or an earlier teardown already destroyed
         //   this name along with everything above its own target.
         if ($index < 0) {
            return $this->fail('ROLLBACK', [], 'SQL transaction savepoint is not available.');
         }

         $target = $this->savepoints[$index];

         // @ ROLLBACK TO destroys every savepoint established after its target
         //   and keeps the target itself, so a named rollback truncates the
         //   stack just above it. Retiring exactly one level left every newer
         //   name in place and the framework then named savepoints the server
         //   had already destroyed. The unnamed form ends the level it names,
         //   which is this API's own contract, so it drops the target too.
         $this->savepoints = array_slice($this->savepoints, 0, $name === null ? $index : $index + 1);
         $this->depth = count($this->savepoints) + 1;

         $savepoint = $this->quote($target);

         return $this->create("ROLLBACK TO SAVEPOINT {$savepoint}");
      }

      // ---
      $this->discard();

      $Operation = $this->create('ROLLBACK', unlock: true);
      $this->depth = 0;
      $this->savepoints = [];
      $this->Connection = null;

      return $Operation;
   }

   /**
    * Create one nested transaction savepoint.
    */
   public function save (null|string $name = null): Operation
   {
      if ($this->ready() === false) {
         return $this->fail('SAVEPOINT', [], 'SQL transaction operation is still active.');
      }

      if ($this->active() === false) {
         return $this->fail('SAVEPOINT', [], 'SQL transaction is not active.');
      }

      $name ??= "bootgly_{$this->saves}";
      $this->saves++;
      $this->savepoints[] = $name;
      $this->depth++;

      $savepoint = $this->quote($name);

      return $this->create("SAVEPOINT {$savepoint}");
   }

   /**
    * Release the current nested transaction savepoint.
    */
   public function release (null|string $name = null): Operation
   {
      if ($this->ready() === false) {
         return $this->fail('RELEASE SAVEPOINT', [], 'SQL transaction operation is still active.');
      }

      if ($this->active() === false || $this->depth <= 1) {
         return $this->fail('RELEASE SAVEPOINT', [], 'SQL transaction savepoint is not active.');
      }

      $index = $this->locate($name);

      // ?
      if ($index < 0) {
         return $this->fail('RELEASE SAVEPOINT', [], 'SQL transaction savepoint is not available.');
      }

      $target = $this->savepoints[$index];

      // @ RELEASE destroys its target and everything established after it, so
      //   the stack truncates below it either way.
      $this->savepoints = array_slice($this->savepoints, 0, $index);
      $this->depth = count($this->savepoints) + 1;

      $savepoint = $this->quote($target);

      return $this->create("RELEASE SAVEPOINT {$savepoint}");
   }

   /**
    * Create and assign one SQL operation to the transaction connection.
    *
    * @param array<int|string,mixed> $parameters
    */
   private function create (string $sql, array $parameters = [], bool $lock = false, bool $unlock = false): Operation
   {
      $this->attach();

      $Operation = new Operation($this->Connection, $sql, $parameters, $this->Database->Config->timeout);
      $Operation->lock = $lock;
      $Operation->unlock = $unlock;

      $this->Database->Pool->assign($Operation);
      $this->attach($Operation->Connection);
      $this->Operation = $Operation;

      return $Operation;
   }

   /**
    * Attach the transaction to a pool-assigned connection.
    */
   private function attach (null|Connection $Connection = null): null|Connection
   {
      if ($Connection !== null) {
         $this->Connection = $Connection;

         return $this->Connection;
      }

      if ($this->Connection === null && $this->Operation !== null && $this->Operation->Connection !== null) {
         $this->Connection = $this->Operation->Connection;
      }

      return $this->Connection;
   }

   /**
    * Check whether the transaction is ready to accept SQL operations.
    */
   private function active (): bool
   {
      $this->attach();

      return $this->depth > 0 && $this->Connection !== null;
   }

   /**
    * Check whether the previous transaction operation has completed.
    */
   private function ready (): bool
   {
      return $this->Operation === null || $this->Operation->finished;
   }

   /**
    * Discard the statement the top-level teardown overtakes.
    */
   private function discard (): void
   {
      $Operation = $this->Operation;

      // ? Nothing outstanding — the teardown is the only statement in flight.
      if ($Operation === null || $Operation->finished) {
         return;
      }

      // @ The driver may still own this statement's wire: let it reconcile there
      //   first, exactly as the pool does for a deadline it expired out of band.
      $Operation->Protocol?->abandon($Operation);

      // ? A command the wire never carried must never reach it. The transaction
      //   that would have contained it is ending, so flushing it afterwards
      //   would run it in autocommit — on a connection the pool has by then
      //   handed to somebody else. fail() drops the buffered command with it.
      if ($Operation->finished === false) {
         $Operation->fail('SQL transaction statement was discarded by the rollback.');
      }

      // ! Detach it from the pool for good. The caller still holds this object —
      //   Repository::save() returns it and Hooks::Saved publishes it — and
      //   awaiting a finished operation still reaches Pool::release(), which
      //   would hand back a connection the pool has since lent to somebody else.
      $Operation->Protocol = null;
      $Operation->Connection = null;
   }

   /**
    * Create one failed SQL operation without touching the pool.
    *
    * @param array<int|string,mixed> $parameters
    */
   private function fail (string $sql, array $parameters, string $message): Operation
   {
      $Operation = new Operation($this->Connection, $sql, $parameters, $this->Database->Config->timeout);
      $Operation->fail($message);

      // ! The refused operation never becomes the tracked one: it is already
      //   finished, so adopting it would report the serial surface as free while
      //   the statement that caused the refusal is still in flight — one
      //   rejection would then admit everything after it.

      return $Operation;
   }

   /**
    * Locate one live savepoint on the local stack, most recent first.
    */
   private function locate (null|string $name): int
   {
      // ?: An unnamed target is whatever sits on top of the stack.
      if ($name === null) {
         return count($this->savepoints) - 1;
      }

      // @@ Both engines resolve a duplicated savepoint name to the most recent
      //    one, so this walks backwards: array_search() finds the oldest and
      //    would truncate the stack far below what the server destroyed.
      for ($index = count($this->savepoints) - 1; $index >= 0; $index--) {
         if ($this->savepoints[$index] === $name) {
            return $index;
         }
      }

      // :
      return -1;
   }

   /**
    * Quote one SQL identifier for savepoint statements.
    */
   private function quote (string $name): string
   {
      return $this->Database->Dialect->quote($name);
   }
}
