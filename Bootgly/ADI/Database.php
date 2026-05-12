<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI;


use Bootgly\ADI\Database\Config;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Operation;
use Bootgly\ADI\Database\Pool;
use Bootgly\ADI\Database\Pools;


/**
 * Canonical ADI database entry point.
 *
 * This facade is intentionally transport-agnostic. Async scheduling stays in
 * ACI/WPI through readiness tokens; ADI only creates operations and owns data
 * protocol state.
 */
class Database
{
   // * Config
   public Config $Config;

   // * Data
   public Connection $Connection;
   public Pool $Pool;
   public Pools $Pools;

   // * Metadata
   // ...


   /**
    * Create a database facade from ADI-native config data.
    *
    * @param array<string,mixed>|Config $config
    */
   public function __construct (array|Config $config = [])
   {
      // * Config
      $this->Config = $config instanceof Config
         ? $config
         : new Config($config);

      // * Data
      $this->Connection = new Connection($this->Config);
      $this->Pools = new Pools($this->Config, $this->Connection);
      $this->Pool = $this->Pools->fetch($this->Config->driver);
   }

   /**
    * Create an async database operation.
    *
    * The returned Operation is advanced by protocol-specific code and awaited
    * by platform code through ACI readiness tokens.
    *
    * @param array<int|string,mixed> $parameters
    */
   public function query (string $sql, array $parameters = []): Operation
   {
      return $this->Pool->query($sql, $parameters);
   }

   /**
    * Advance an async database operation through the selected protocol.
    */
   public function advance (Operation $Operation): Operation
   {
      return $this->Pool->advance($Operation);
   }

   /**
    * Cancel one running database operation when supported by the protocol.
    */
   public function cancel (Operation $Operation): Operation
   {
      return $this->Pool->cancel($Operation);
   }
}
