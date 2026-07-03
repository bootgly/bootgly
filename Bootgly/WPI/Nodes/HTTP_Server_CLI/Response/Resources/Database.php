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
use Bootgly\ADI\Databases\SQL\Awaiting;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\ADI\Databases\SQL\Repository;
use Bootgly\ADI\Databases\SQL\Transaction;
use Bootgly\API\Environment\Configs;
use Bootgly\API\Environment\Configs\Config;
use Bootgly\API\Environment\Configs\DatabaseConfig;
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

   // * Data
   private null|Closure $Wait = null;
   // ! Bound by attach() on mount (the per-request HTTP path). Plain nullable —
   //   a get hook here costs ~2% CPU on 1-query routes (kills engine inlining),
   //   and a null scope falls through to the SQL facade's default resolution
   private null|object $Scope = null;

   // * Metadata
   // ...


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
      return $this->Database->map($Entity, $this->Scope, $this);
   }

   /**
    * Create and await one SQL operation.
    *
    * @param string|Builder|Query $query
    * @param array<int|string,mixed> $parameters
    */
   public function query (string|Builder|Query $query, array $parameters = [], null|object $Scope = null): Operation
   {
      return $this->await($this->Database->query($query, $parameters, $Scope ?? $this->Scope));
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
         $this->Database->touch($this->Scope);

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
