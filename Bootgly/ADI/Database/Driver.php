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
