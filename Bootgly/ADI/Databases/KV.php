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


use Bootgly\ADI\Database;
use Bootgly\ADI\Database\Config;
use Bootgly\ADI\Databases\KV\Drivers;
use Bootgly\ADI\Databases\KV\Operation;


/**
 * Key-value database facade.
 *
 * The KV counterpart to the SQL facade: it wires the shared async DBAL core
 * (config, connection, pool) to KV drivers and exposes a single `command()`
 * verb. The Redis driver speaks RESP over the non-blocking connection pool.
 */
class KV extends Database
{
   /**
    * @param array<string,mixed>|Config $config
    */
   public function __construct (array|Config $config = [])
   {
      parent::__construct($config, Drivers::class);
   }

   /**
    * Create an async key-value command operation.
    *
    * @param array<int,mixed> $arguments
    */
   public function command (string $command, array $arguments = []): Operation
   {
      $Operation = new Operation(null, $command, $arguments, $this->Pool->Config->timeout);
      $this->Pool->assign($Operation);

      return $Operation;
   }

   /**
    * Advance an async key-value operation through the owning pool.
    */
   public function advance (Operation $Operation): Operation
   {
      $Pool = $Operation->Pool ?? $this->Pool;
      $Pool->advance($Operation);

      return $Operation;
   }

   /**
    * Await one key-value operation through the owning pool.
    */
   public function await (Operation $Operation): Operation
   {
      $Pool = $Operation->Pool ?? $this->Pool;
      $Pool->wait($Operation);

      return $Operation;
   }

   /**
    * Cancel one running key-value operation when supported by the driver.
    */
   public function cancel (Operation $Operation): Operation
   {
      $Pool = $Operation->Pool ?? $this->Pool;
      $Pool->cancel($Operation);

      return $Operation;
   }
}
