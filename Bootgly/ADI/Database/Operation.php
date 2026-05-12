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
use Bootgly\ADI\Database\Connection\Protocols\Driver;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Database\OperationStates;


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
   public string $sql;
   /** @var array<int|string,mixed> */
   public array $parameters;
   public float $timeout;

   // * Data
   public null|Driver $Protocol = null;
   public string $statement = '';
   public string $portal = '';
   public bool $prepared = false;
   public OperationStates $state = OperationStates::Pending;
   public string $write = '';
   public string $status = '';
   /** @var array<int,array<string,mixed>> */
   public array $rows = [];
   /** @var array<int,string> */
   public array $columns = [];
   /** @var array<int,int> */
   public array $types = [];
   /** @var array<int,int> */
   public array $parameterTypes = [];
   public int $affected = 0;
   public private(set) null|Readiness $Readiness = null;
   public private(set) null|Result $Result = null;
   public private(set) null|string $error = null;
   public private(set) bool $finished = false;
   /** CancelRequest was sent; the main operation still resolves, fails or expires later. */
   public bool $cancelled = false;

   // * Metadata
   public private(set) float $started;
   public private(set) float $deadline;


   /**
    * Create a pending operation.
    *
    * @param array<int|string,mixed> $parameters
    */
   public function __construct (null|Connection $Connection, string $sql, array $parameters = [], float $timeout = 0.0)
   {
      // * Config
      $this->Connection = $Connection;
      $this->sql = $sql;
      $this->parameters = $parameters;
      $this->timeout = $timeout;

      // * Metadata
      $this->started = microtime(true);
      $this->deadline = $timeout > 0.0 ? $this->started + $timeout : 0.0;
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
      $this->write = '';
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
      $this->write = '';
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
