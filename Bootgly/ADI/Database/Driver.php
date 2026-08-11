<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Database;


use Bootgly\ADI\Database\Config;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Operation;


/**
 * Database wire driver base.
 *
 * Concrete drivers own protocol-specific encoding, decoding and operation
 * progression while Database keeps a single transport-agnostic core.
 */
abstract class Driver
{
   // * Config
   public Config $Config;
   public Connection $Connection;

   // * Data
   // ...

   // * Metadata
   // ...


   public function __construct (Config $Config, Connection $Connection)
   {
      // * Config
      $this->Config = $Config;
      $this->Connection = $Connection;
   }

   /**
    * Prepare an existing operation for this driver.
    */
   abstract public function prepare (Operation $Operation): Operation;

   /**
    * Advance a pending operation through the driver state machine.
    */
   abstract public function advance (Operation $Operation): Operation;

   /**
    * Cancel one running operation when the concrete driver supports it.
    */
   public function cancel (Operation $Operation): Operation
   {
      return $Operation->fail('Database driver does not support cancellation.');
   }

   /**
    * Reconcile the wire when the pool abandons one operation.
    *
    * The pool finishes operations from the outside — an elapsed deadline —
    * while the server may still be answering them, and a fallback retry may
    * start running the very same object on another connection. A driver that
    * holds wire state for the abandoned operation reconciles it here: it
    * either keeps owning the wire until the response has been drained, or
    * drops the session when the remaining bytes can no longer be attributed.
    * Drivers that keep no such state do nothing.
    */
   public function abandon (Operation $Operation): void
   {
   }

   /**
    * Check whether this driver still has in-flight operations.
    */
   public function check (): bool
   {
      return false;
   }

   /**
    * Drain operations completed internally by this driver.
    *
    * @return array<int,Operation>
    */
   public function drain (): array
   {
      return [];
   }
}
