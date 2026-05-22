<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Database;


use function array_key_first;
use function array_shift;
use function count;
use function is_resource;
use function microtime;
use function mt_rand;
use function spl_object_id;
use function stream_select;
use RuntimeException;

use Bootgly\ACI\Events\Scheduler;
use Bootgly\ADI\Database\Config;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Connection\ConnectionStates;
use Bootgly\ADI\Database\Driver;
use Bootgly\ADI\Database\Drivers;
use Bootgly\ADI\Database\Operation;
use Bootgly\ADI\Database\Operation\OperationStates;


/**
 * Async database connection pool.
 */
class Pool
{
   public const int DEFAULT_FAILURES = 2;
   public const float DEFAULT_RETRY = 5.0;
   public const float DEFAULT_JITTER = 0.25;

   // * Config
   public Config $Config;
   public Connection $Connection;
   public int $min;
   public int $max;
   /** @var class-string<Drivers> */
   public string $drivers;

   // * Data
   /** @var array<int,Connection> */
   public array $idle = [];
   /** @var array<int,Connection> */
   public array $busy = [];
   /** @var array<int,Operation> */
   public array $pending = [];
   public int $created = 0;
   public private(set) int $failures = 0;
   public private(set) float $retry = 0.0;

   // * Metadata
   public bool $healthy {
      get {
         return $this->retry <= 0.0 || microtime(true) >= $this->retry;
      }
   }
   /** @var array<int,true> */
   private array $counted = [];
   /** @var array<int,true> */
   private array $locked = [];
   // @ Round-robin cursor for co-locating pipelined operations across connections.
   private int $cursor = 0;


   /**
    * @param class-string<Drivers> $drivers
    */
   public function __construct (Config $Config, Connection $Connection, string $drivers = Drivers::class)
   {
      // * Config
      $this->Config = $Config;
      $this->Connection = $Connection;
      $this->min = $Config->pool['min'];
      $this->max = $Config->pool['max'];
      $this->drivers = $drivers;
   }

   /**
    * Attach an existing ready connection to the idle pool.
    */
   public function attach (Connection $Connection): self
   {
      $id = spl_object_id($Connection);
      $this->track($Connection);

      $this->idle[$id] = $Connection;

      return $this;
   }

   /**
    * Advance an operation through its assigned protocol and release on finish.
    */
   public function advance (Operation $Operation): Operation
   {
      $Pool = $Operation->Pool;

      if ($Pool !== null && $Pool !== $this) {
         return $Pool->advance($Operation);
      }

      if ($this->fallback($Operation)) {
         return $Operation;
      }

      if ($Operation->expire()) {
         $this->forget($Operation);
         $this->release($Operation);

         $this->fallback($Operation);

         return $Operation;
      }

      if ($Operation->state === OperationStates::Pending) {
         $this->assign($Operation);
         $this->fallback($Operation);

         return $Operation;
      }

      // @ assign() always sets a Driver on Pending → !Pending operations.
      //   The null guard covers raw Operations that bypass assign().
      $Protocol = $Operation->Protocol;

      if ($Protocol === null) {
         $Operation = $this->assign($Operation);
         $Protocol = $Operation->Protocol;

         if ($Protocol === null) {
            return $Operation;
         }
      }

      $Protocol->advance($Operation);
      $released = $this->drain($Protocol, $Operation);

      if ($Operation->finished && $released === false) {
         $this->release($Operation);
      }

      $this->fallback($Operation);

      return $Operation;
   }

   /**
    * Wait for one operation to finish using its readiness hints.
    */
   public function wait (Operation $Operation): Operation
   {
      $Pool = $Operation->Pool;

      if ($Pool !== null && $Pool !== $this) {
         return $Pool->wait($Operation);
      }

      while (true) {
         $this->advance($Operation);

         $Readiness = $Operation->Readiness;
         if ($Operation->finished) {
            break;
         }

         if ($Readiness === null) {
            throw new RuntimeException('Database operation did not provide readiness.');
         }

         $read = [];
         $write = [];
         $except = [];

         if ($Readiness->flag === Scheduler::SCHEDULE_READ) {
            $read[] = $Readiness->socket;
         }
         else {
            $write[] = $Readiness->socket;
         }

         $selected = stream_select($read, $write, $except, 1, 0);
         if ($selected === false) {
            throw new RuntimeException('Database operation readiness wait failed.');
         }
      }

      if ($Operation->error !== null) {
         throw new RuntimeException($Operation->error);
      }

      return $Operation;
   }

   /**
    * Cancel one operation through its assigned protocol.
    */
   public function cancel (Operation $Operation): Operation
   {
      $Pool = $Operation->Pool;

      if ($Pool !== null && $Pool !== $this) {
         return $Pool->cancel($Operation);
      }

      $Protocol = $Operation->Protocol;

      if ($Protocol === null) {
         return $Operation->fail('Database operation has no protocol to cancel.');
      }

      return $Protocol->cancel($Operation);
   }

   /**
    * Drain protocol-completed operations and release failures first.
    */
   private function drain (Driver $Protocol, Operation $Operation): bool
   {
      $Completed = $Protocol->drain();
      $released = false;

      // @ Failure-first release is deliberate: a failed sibling may be the
      //   operation that decides whether the shared connection is reusable,
      //   while later successful releases remain idempotent.
      foreach ($Completed as $CompletedOperation) {
         if ($CompletedOperation->state !== OperationStates::Failed) {
            continue;
         }

         if ($CompletedOperation === $Operation) {
            $released = true;
         }

         $this->release($CompletedOperation);
      }

      foreach ($Completed as $CompletedOperation) {
         if ($CompletedOperation->state === OperationStates::Failed) {
            continue;
         }

         if ($CompletedOperation === $Operation) {
            $released = true;
         }

         $this->release($CompletedOperation);
      }

      return $released;
   }

   /**
    * Release an operation connection back to the pool when reusable.
    */
   public function release (Operation $Operation): self
   {
      $Pool = $Operation->Pool;

      if ($Pool !== null && $Pool !== $this) {
         $Pool->release($Operation);

         return $this;
      }

      $Connection = $Operation->Connection;

      if ($Connection === null) {
         return $this;
      }

      $id = spl_object_id($Connection);
      $Protocol = $Operation->Protocol;

      if ($Protocol !== null && $Protocol->check()) {
         return $this;
      }

      if ($Operation->unlock || ($Operation->lock && $Operation->state === OperationStates::Failed)) {
         $this->unlock($Connection);
      }

      unset($this->busy[$id]);

      $alive = is_resource($Connection->socket);

      if ($alive === false || $Connection->state !== ConnectionStates::Ready) {
         unset($this->idle[$id]);
         unset($this->locked[$id]);

         if ($alive) {
            $Connection->disconnect();
         }

         $this->drop($Connection);

         $this->promote();

         return $this;
      }

      if ($Operation->state === OperationStates::Finished) {
         $this->recover();
      }

      if (isset($this->locked[$id])) {
         $this->busy[$id] = $Connection;

         return $this;
      }

      $this->idle[$id] = $Connection;
      $this->promote();

      return $this;
   }

   /**
    * Assign an available connection and protocol to an operation.
    */
   public function assign (Operation $Operation): Operation
   {
      $Operation->Pool = $this;
      $Connection = $this->acquire($Operation->Connection, $Operation->lock === false);

      if ($Connection === null) {
         $Operation->state = OperationStates::Pending;

         if ($this->check($Operation) === false) {
            $this->pending[] = $Operation;
         }

         return $Operation;
      }

      $Protocol = $this->create($Connection);
      $Operation->Connection = $Connection;
      $Operation->Protocol = $Protocol;

      if ($Operation->lock) {
         $this->lock($Connection);
      }

      $Operation = $Protocol->prepare($Operation);

      if ($Operation->finished) {
         $this->release($Operation);
      }

      return $Operation;
   }

   /**
    * Quarantine this pool after a failed fallback.
    */
   public function penalize (float $seconds = self::DEFAULT_RETRY, int $failures = self::DEFAULT_FAILURES, float $jitter = self::DEFAULT_JITTER): self
   {
      $this->failures++;

      if ($this->failures < $failures) {
         return $this;
      }

      $spread = 0.0;
      $limit = (int) ($jitter * 1000000);

      if ($limit > 0) {
         $spread = mt_rand(1, $limit) / 1000000;
      }

      $this->retry = microtime(true) + $seconds + $spread;

      return $this;
   }

   /**
    * Clear replica health penalty after a successful operation.
    */
   public function recover (): self
   {
      $this->failures = 0;
      $this->retry = 0.0;

      return $this;
   }

   /**
    * Reserve one pool connection for an owner such as a SQL transaction.
    */
   public function lock (Connection $Connection): self
   {
      $id = spl_object_id($Connection);

      if (isset($this->counted[$id])) {
         $this->locked[$id] = true;
      }

      return $this;
   }

   /**
    * Release a reserved pool connection back to normal pool scheduling.
    */
   public function unlock (Connection $Connection): self
   {
      unset($this->locked[spl_object_id($Connection)]);

      return $this;
   }

   /**
    * Acquire an idle connection or reserve capacity for a new one.
    */
   private function acquire (null|Connection $Pinned = null, bool $pipelineable = true): null|Connection
   {
      if ($Pinned !== null) {
         $id = spl_object_id($Pinned);

         if (isset($this->counted[$id]) === false || is_resource($Pinned->socket) === false) {
            return null;
         }

         unset($this->idle[$id]);
         $this->busy[$id] = $Pinned;

         return $Pinned;
      }

      $id = array_key_first($this->idle);

      if ($id !== null) {
         $Connection = $this->idle[$id];
         unset($this->idle[$id]);
         $this->busy[$id] = $Connection;

         return $Connection;
      }

      if ($this->created >= $this->max) {
         // @ Pool exhausted — co-locate this operation on a ready busy
         //   connection so the driver pipelines it instead of queueing it
         //   pending. Exclusive operations (transactions) never co-locate.
         if ($pipelineable === false) {
            return null;
         }

         /** @var array<int,Connection> $Eligible */
         $Eligible = [];

         foreach ($this->busy as $id => $Connection) {
            if (isset($this->locked[$id])) {
               continue;
            }

            $Protocol = $Connection->Protocol;

            if (
               $Protocol !== null
               && $Connection->connected
               && $Connection->state === ConnectionStates::Ready
               && is_resource($Connection->socket)
            ) {
               $Eligible[] = $Connection;
            }
         }

         if ($Eligible === []) {
            return null;
         }

         $Connection = $Eligible[$this->cursor % count($Eligible)];
         $this->cursor++;

         return $Connection;
      }

      $Connection = $this->created === 0
         ? $this->Connection
         : new Connection($this->Config);
      $id = spl_object_id($Connection);
      $this->track($Connection);
      $this->busy[$id] = $Connection;

      return $Connection;
   }
   /**
    * Track one pool-owned connection.
    */
   private function track (Connection $Connection): void
   {
      $id = spl_object_id($Connection);

      if (isset($this->counted[$id])) {
         return;
      }

      $this->counted[$id] = true;
      $this->created++;
   }

   /**
    * Drop one pool-owned connection from bookkeeping.
    */
   private function drop (Connection $Connection): void
   {
      $id = spl_object_id($Connection);

      if (isset($this->counted[$id]) === false) {
         return;
      }

      unset($this->counted[$id]);

      if ($this->created > 0) {
         $this->created--;
      }
   }

   /**
    * Check whether an operation is already pending.
    */
   private function check (Operation $Operation): bool
   {
      foreach ($this->pending as $Pending) {
         if ($Pending === $Operation) {
            return true;
         }
      }

      return false;
   }

   /**
    * Forget one pending operation.
    */
   private function forget (Operation $Operation): void
   {
      foreach ($this->pending as $id => $Pending) {
         if ($Pending === $Operation) {
            unset($this->pending[$id]);
         }
      }
   }

   /**
    * Retry a failed operation through its fallback pool once.
    */
   private function fallback (Operation $Operation): bool
   {
      $FallbackPool = $Operation->FallbackPool;

      if ($FallbackPool === null || $FallbackPool === $this || $Operation->fallback || $Operation->cancelled || $Operation->state !== OperationStates::Failed) {
         return false;
      }

      if ($Operation->quarantine) {
         $this->penalize();
      }

      $Operation->fallback = true;
      $Operation->retry();
      $FallbackPool->assign($Operation);

      return true;
   }

   /**
    * Create a protocol instance bound to a connection.
    */
   private function create (Connection $Connection): Driver
   {
      $Protocol = $Connection->Protocol;

      if ($Protocol !== null) {
         return $Protocol;
      }

      $drivers = $this->drivers;
      $Drivers = new $drivers($this->Config, $Connection);
      $Driver = $Drivers->fetch($this->Config->driver);

      $Connection->bind($Driver);

      return $Driver;
   }

   /**
    * Promote pending operations while capacity is available.
    */
   private function promote (): void
   {
      while ($this->pending !== [] && ($this->idle !== [] || $this->created < $this->max)) {
         $Operation = array_shift($this->pending);

         if ($Operation->expire()) {
            continue;
         }

         $this->assign($Operation);
         $this->advance($Operation);
      }
   }
}
