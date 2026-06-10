<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\KV;


use Bootgly\ADI\Database\Config as DatabaseConfig;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Drivers as DatabaseDrivers;
use Bootgly\ADI\Databases\KV\Drivers\Redis;


/**
 * Registry of key-value database drivers.
 */
class Drivers extends DatabaseDrivers
{
   public function __construct (DatabaseConfig $Config, Connection $Connection)
   {
      parent::__construct($Config, $Connection);

      $this->register('redis', new Redis($Config, $Connection));
   }
}
