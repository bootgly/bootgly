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


use function microtime;

use Bootgly\ACI\Events\Readiness;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Driver;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Database\Operation\Result;


/**
 * Pending database operation.
 *
 * Protocol implementations will advance this object until Result is available
 * or an error is set. Platform code awaits Readiness without ADI knowing about
 * HTTP responses or Fibers.
 */
class Operation
{
   // * Config
   public null|Connection $Connection;
   /** @var array<int|string,mixed> */
   public array $parameters;
   public float $timeout;

   // * Data
   public null|Driver $Protocol = null;
   public OperationStates $state = OperationStates::Pending;
   public private(set) null|Readiness $Readiness = null;
   public private(set) null|Result $Result = null;
   public private(set) null|string $error = null;
   public private(set) bool $finished = false;
   /** Keep the assigned pool connection reserved after this operation completes. */
   public bool $lock = false;
   /** Release a previously reserved pool connection after this operation completes. */
   public bool $unlock = false;
   /** CancelRequest was sent; the main operation still resolves, fails or expires later. */
   public bool $cancelled = false;

   // * Metadata
   public private(set) float $deadline;


   /**
    * Create a pending operation.
    *
    * @param array<int|string,mixed> $parameters
    */
   public function __construct (null|Connection $Connection, array $parameters = [], float $timeout = 0.0)
   {
      // * Config
      $this->Connection = $Connection;
      $this->parameters = $parameters;
      $this->timeout = $timeout;

      // * Metadata
      // @ Skip microtime() syscall when no timeout is configured.
      $this->deadline = $timeout > 0.0 ? microtime(true) + $timeout : 0.0;
   }

   /**
    * Attach event-loop readiness to this operation.
    */
   public function await (Readiness $Readiness): self
   {
      $this->Readiness = $Readiness;

      return $this;
   }

   /**
    * Resolve this operation with a result.
    */
   public function resolve (Result $Result): self
   {
      $this->Result = $Result;
      $this->Readiness = null;
      $this->finished = true;
      $this->state = OperationStates::Finished;

      return $this;
   }

   /**
    * Fail this operation with an error message.
    */
   public function fail (string $error): self
   {
      $this->error = $error;
      $this->Readiness = null;
      $this->finished = true;
      $this->state = OperationStates::Failed;

      return $this;
   }

   /**
    * Expire this operation when its deadline is reached.
    */
   public function expire (): bool
   {
      if ($this->finished || $this->deadline <= 0.0 || microtime(true) < $this->deadline) {
         return false;
      }

      $this->fail("Database operation timed out after {$this->timeout} seconds.");

      return true;
   }
}
