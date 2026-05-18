<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources;


use BackedEnum;
use Closure;
use RuntimeException;
use Stringable;
use Throwable;

use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\ADI\Databases\SQL\Transaction;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource\Scheduling;


/**
 * HTTP response resource for awaiting async SQL database operations.
 */
class Database extends Resource implements Scheduling
{
   // * Config
   public SQL $Database;

   // * Data
   private null|Closure $Wait = null;

   // * Metadata
   // ...


   public function __construct (SQL $Database)
   {
      parent::__construct();

      // * Config
      $this->Database = $Database;
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
    * Create and await one SQL operation.
    *
    * @param string|Builder|Query $query
    * @param array<int|string,mixed> $parameters
    */
   public function query (string|Builder|Query $query, array $parameters = []): Operation
   {
      return $this->await($this->Database->query($query, $parameters));
   }

   /**
    * Create and await one SQL operation, throwing when it fails.
    *
    * @param string|Builder|Query $query
    * @param array<int|string,mixed> $parameters
    */
   public function fetch (string|Builder|Query $query, array $parameters = []): Result
   {
      $Operation = $this->query($query, $parameters);
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
      while ($Operation->finished === false) {
         $Operation = $this->Database->advance($Operation);

         if ($Operation->finished) {
            break;
         }

         $Wait = $this->Wait;

         if ($Wait === null) {
            throw new RuntimeException('Database response resource is not bound.');
         }

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
      while (true) {
         $waiting = null;
         $pending = false;

         foreach ($Operations as $id => $Operation) {
            if ($Operation->finished) {
               continue;
            }

            $Operation = $this->Database->advance($Operation);
            $Operations[$id] = $Operation;

            if ($Operation->finished === false) {
               $pending = true;
               $waiting ??= $Operation->Readiness;
            }
         }

         if ($pending === false) {
            break;
         }

         $Wait = $this->Wait;

         if ($Wait === null) {
            throw new RuntimeException('Database response resource is not bound.');
         }

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
      $Transaction = $this->Database->begin();
      $Begin = $Transaction->Operation;

      if ($Begin !== null) {
         $this->await($Begin);
         $this->check($Begin);
      }

      try {
         $result = $work($Transaction, $this);
         $Commit = $this->await($Transaction->commit());
         $this->check($Commit);

         return $result;
      }
      catch (Throwable $Throwable) {
         try {
            $this->await($Transaction->rollback());
         }
         catch (Throwable) {
            // Preserve the original work failure.
         }

         throw $Throwable;
      }
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
