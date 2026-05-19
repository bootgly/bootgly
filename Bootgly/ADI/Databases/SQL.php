<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases;


use function count;
use function microtime;
use BackedEnum;
use Fiber;
use Stringable;
use WeakMap;

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

   // * Data
   /** @var array<int,Pool> */
   public array $ReplicaPools = [];

   // * Metadata
   private null|Schema $Schema = null;
   private int $replica = 0;
   /** @var WeakMap<object,float> */
   private WeakMap $Written;
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
      $Normalized = new Normalized($query, $parameters);
      $Scope = $this->resolve($Scope);
      $Pool = $this->route($Normalized, $Scope);
      $Operation = new Operation(null, $Normalized->sql, $Normalized->parameters, $Pool->Config->timeout);

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
