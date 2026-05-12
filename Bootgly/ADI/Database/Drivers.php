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


use Bootgly\ABI\Data\Registry;
use Bootgly\ADI\Database\Config;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Driver;


/**
 * Registry of available database drivers for one paradigm.
 *
 * @extends Registry<Driver>
 */
class Drivers extends Registry
{
   // * Config
   public Config $Config;
   public Connection $Connection;

   public function __construct (Config $Config, Connection $Connection)
   {
      parent::__construct('Database driver');

      // * Config
      $this->Config = $Config;
      $this->Connection = $Connection;
   }

   /**
    * Register a driver by driver name.
    */
   public function register (string $driver, Driver $Driver): self
   {
      $this->store($driver, $Driver);

      return $this;
   }

   /**
    * Fetch a driver by driver name.
    */
   public function fetch (string $driver = ''): Driver
   {
      if ($driver === '') {
         $driver = $this->Config->driver;
      }

      $Driver = $this->load($driver);

      return $Driver;
   }
}
