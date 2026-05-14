<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL;


use function array_pop;
use function array_search;
use BackedEnum;
use Stringable;

use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Databases\SQL as SQLDatabase;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Query;


/**
 * SQL transaction pinned to one pooled database connection.
 */
class Transaction
{
   // * Config
   public SQLDatabase $Database;

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
   public function query (string|Builder|Query $query, array $parameters = []): Operation
   {
      $Normalized = new Normalized($query, $parameters);
      $sql = $Normalized->sql;
      $parameters = $Normalized->parameters;

      if ($this->ready() === false) {
         return $this->fail($sql, $parameters, 'SQL transaction operation is still active.');
      }

      if ($this->active() === false) {
         return $this->fail($sql, $parameters, 'SQL transaction is not active.');
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
      if ($this->ready() === false) {
         return $this->fail('ROLLBACK', [], 'SQL transaction operation is still active.');
      }

      if ($this->active() === false) {
         return $this->fail('ROLLBACK', [], 'SQL transaction is not active.');
      }

      if ($this->depth > 1 || $name !== null) {
         $name ??= (string) array_pop($this->savepoints);

         if ($name === '') {
            return $this->fail('ROLLBACK', [], 'SQL transaction savepoint is not available.');
         }

         $this->forget($name);

         if ($this->depth > 1) {
            $this->depth--;
         }

         $savepoint = $this->quote($name);

         return $this->create("ROLLBACK TO SAVEPOINT {$savepoint}");
      }

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

      $name ??= (string) array_pop($this->savepoints);

      if ($name === '') {
         return $this->fail('RELEASE SAVEPOINT', [], 'SQL transaction savepoint is not available.');
      }

      $this->forget($name);
      $this->depth--;

      $savepoint = $this->quote($name);

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
    * Create one failed SQL operation without touching the pool.
    *
    * @param array<int|string,mixed> $parameters
    */
   private function fail (string $sql, array $parameters, string $message): Operation
   {
      $Operation = new Operation($this->Connection, $sql, $parameters, $this->Database->Config->timeout);
      $Operation->fail($message);
      $this->Operation = $Operation;

      return $Operation;
   }

   /**
    * Remove a savepoint name from the local stack.
    */
   private function forget (string $name): void
   {
      $index = array_search($name, $this->savepoints, true);

      if ($index !== false) {
         unset($this->savepoints[$index]);
         $this->savepoints = [...$this->savepoints];
      }
   }

   /**
    * Quote one SQL identifier for savepoint statements.
    */
   private function quote (string $name): string
   {
      return $this->Database->Dialect->quote($name);
   }
}
