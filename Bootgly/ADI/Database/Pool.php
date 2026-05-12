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
use function is_resource;
use function spl_object_id;

use Bootgly\ADI\Database\Config;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Connection\Protocols;
use Bootgly\ADI\Database\Connection\Protocols\Driver;
use Bootgly\ADI\Database\ConnectionStates;
use Bootgly\ADI\Database\Operation;
use Bootgly\ADI\Database\OperationStates;


/**
 * Async database connection pool.
 */
class Pool
{
   // * Config
   public Config $Config;
   public Connection $Connection;
   public int $min;
   public int $max;

   // * Data
   /** @var array<int,Connection> */
   public array $idle = [];
   /** @var array<int,Connection> */
   public array $busy = [];
   /** @var array<int,Operation> */
   public array $pending = [];
   public int $created = 0;

   // * Metadata
   /** @var array<int,true> */
   private array $counted = [];


   public function __construct (Config $Config, Connection $Connection)
   {
      // * Config
      $this->Config = $Config;
      $this->Connection = $Connection;
      $this->min = $Config->pool['min'];
      $this->max = $Config->pool['max'];
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
    * Create or queue a pooled query operation.
    *
    * @param array<int|string,mixed> $parameters
    */
   public function query (string $sql, array $parameters = []): Operation
   {
      $Operation = new Operation(null, $sql, $parameters, $this->Config->timeout);

      return $this->assign($Operation);
   }

   /**
    * Advance an operation through its assigned protocol and release on finish.
    */
   public function advance (Operation $Operation): Operation
   {
      if ($Operation->expire()) {
         $this->forget($Operation);
         $this->release($Operation);

         return $Operation;
      }

      if ($Operation->state === OperationStates::Pending) {
         return $this->assign($Operation);
      }

      $Protocol = $Operation->Protocol;

      if ($Protocol instanceof Driver === false) {
         $Operation = $this->assign($Operation);
         $Protocol = $Operation->Protocol;
      }

      if ($Protocol instanceof Driver === false) {
         return $Operation;
      }

      $Protocol->advance($Operation);
      $released = $this->drain($Protocol, $Operation);

      if ($Operation->expire()) {
         $this->release($Operation);

         return $Operation;
      }

      if ($Operation->finished && $released === false) {
         $this->release($Operation);
      }

      return $Operation;
   }

   /**
    * Cancel one operation through its assigned protocol.
    */
   public function cancel (Operation $Operation): Operation
   {
      $Protocol = $Operation->Protocol;

      if ($Protocol instanceof Driver === false) {
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
      $Connection = $Operation->Connection;

      if ($Connection === null) {
         return $this;
      }

      $id = spl_object_id($Connection);
      $Protocol = $Operation->Protocol;

      if ($Protocol instanceof Driver && $Protocol->check()) {
         return $this;
      }

      unset($this->busy[$id]);

      if (is_resource($Connection->socket) === false || $Connection->state !== ConnectionStates::Ready) {
         unset($this->idle[$id]);

         if (is_resource($Connection->socket)) {
            $Connection->disconnect();
         }

         $this->drop($Connection);

         $this->promote();

         return $this;
      }

      $this->idle[$id] = $Connection;
      $this->promote();

      return $this;
   }

   /**
    * Assign an available connection and protocol to an operation.
    */
   private function assign (Operation $Operation): Operation
   {
      if ($Operation->expire()) {
         $this->forget($Operation);

         return $Operation;
      }

      $Connection = $this->acquire();

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

      $Operation = $Protocol->prepare($Operation);

      if ($Operation->finished) {
         $this->release($Operation);
      }

      return $Operation;
   }

   /**
    * Acquire an idle connection or reserve capacity for a new one.
    */
   private function acquire (): null|Connection
   {
      $id = array_key_first($this->idle);

      if ($id !== null) {
         $Connection = $this->idle[$id];
         unset($this->idle[$id]);
         $this->busy[$id] = $Connection;

         return $Connection;
      }

      if ($this->created >= $this->max) {
         foreach ($this->busy as $Connection) {
            if ($Connection->connected && $Connection->state === ConnectionStates::Ready && is_resource($Connection->socket) && $Connection->Protocol instanceof Driver && $Connection->Protocol->check()) {
               return $Connection;
            }
         }

         return null;
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
    * Create a protocol instance bound to a connection.
    */
   private function create (Connection $Connection): Driver
   {
      if ($Connection->Protocol instanceof Driver) {
         return $Connection->Protocol;
      }

      $Protocols = new Protocols($this->Config, $Connection);
      $Protocol = $Protocols->fetch($this->Config->driver);

      $Connection->bind($Protocol);

      return $Protocol;
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
