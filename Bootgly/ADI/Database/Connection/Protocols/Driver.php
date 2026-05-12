<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Database\Connection\Protocols;


use Bootgly\ADI\Database\Config;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Operation;


/**
 * Database wire protocol base.
 *
 * Concrete protocols own driver-specific encoding, decoding and operation
 * progression while Database keeps a single transport-agnostic entry point.
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
    * Create a protocol-specific query operation.
    *
    * @param array<int|string,mixed> $parameters
    */
   abstract public function query (string $sql, array $parameters = []): Operation;

   /**
    * Prepare an existing operation for this protocol.
    */
   abstract public function prepare (Operation $Operation): Operation;

   /**
    * Advance a pending operation through the protocol state machine.
    */
   abstract public function advance (Operation $Operation): Operation;

   /**
    * Cancel one running operation when the concrete protocol supports it.
    */
   public function cancel (Operation $Operation): Operation
   {
      return $Operation->fail('Database protocol does not support cancellation.');
   }

   /**
    * Check whether this protocol still has in-flight operations.
    */
   public function check (): bool
   {
      return false;
   }

   /**
    * Drain operations completed internally by this protocol.
    *
    * @return array<int,Operation>
    */
   public function drain (): array
   {
      return [];
   }
}
