<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Database\Connection;


use Bootgly\ABI\Data\Registry;
use Bootgly\ADI\Database\Config;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Connection\Protocols\Driver;
use Bootgly\ADI\Database\Connection\Protocols\PostgreSQL;


/**
 * Repository of available database protocols.
 *
 * @extends Registry<Driver>
 */
class Protocols extends Registry
{
   // * Config
   public Config $Config;
   public Connection $Connection;

   public function __construct (Config $Config, Connection $Connection)
   {
      parent::__construct('Database protocol');

      // * Config
      $this->Config = $Config;
      $this->Connection = $Connection;

      // * Data
      $this->register('pgsql', new PostgreSQL($Config, $Connection));
   }

   /**
    * Register a protocol by driver name.
    */
   public function register (string $driver, Driver $Protocol): self
   {
      $this->store($driver, $Protocol);

      return $this;
   }

   /**
    * Fetch a protocol by driver name.
    */
   public function fetch (string $driver = ''): Driver
   {
      if ($driver === '') {
         $driver = $this->Config->driver;
      }

      $Protocol = $this->load($driver);

      return $Protocol;
   }
}
