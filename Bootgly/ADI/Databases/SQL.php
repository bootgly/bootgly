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
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers;
use Bootgly\ADI\Databases\SQL\Operation;


/**
 * SQL database facade.
 */
class SQL extends Database
{
   // * Config
   public Config $SQLConfig;

   // * Data
   // ...

   // * Metadata
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

      parent::__construct($Config, Drivers::class);
   }

   /**
    * Create an async SQL query operation.
    *
    * @param array<int|string,mixed> $parameters
    */
   public function query (string $sql, array $parameters = []): Operation
   {
      $Operation = new Operation(null, $sql, $parameters, $this->Config->timeout);
      $this->Pool->assign($Operation);

      return $Operation;
   }

   /**
    * Advance an async SQL operation through the selected driver.
    */
   public function advance (Operation $Operation): Operation
   {
      $this->Pool->advance($Operation);

      return $Operation;
   }

   /**
    * Cancel one running SQL operation when supported by the driver.
    */
   public function cancel (Operation $Operation): Operation
   {
      $this->Pool->cancel($Operation);

      return $Operation;
   }
}
