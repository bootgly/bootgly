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
use Bootgly\ADI\Database\Pool;


/**
 * Repository of available database connection pools.
 *
 * @extends Registry<Pool>
 */
class Pools extends Registry
{
   // * Config
   public Config $Config;
   public Connection $Connection;

   public function __construct (Config $Config, Connection $Connection)
   {
      parent::__construct('Database pool');

      // * Config
      $this->Config = $Config;
      $this->Connection = $Connection;

      // * Data
      $this->register('pgsql', new Pool($Config, $Connection));
   }

   /**
    * Register a pool by driver name.
    */
   public function register (string $driver, Pool $Pool): self
   {
      $this->store($driver, $Pool);

      return $this;
   }

   /**
    * Fetch a pool by driver name.
    */
   public function fetch (string $driver = ''): Pool
   {
      if ($driver === '') {
         $driver = $this->Config->driver;
      }

      $Pool = $this->load($driver);

      return $Pool;
   }
}
