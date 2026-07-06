<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL;


use Bootgly\ADI\Database\Config as DatabaseConfig;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Drivers as DatabaseDrivers;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;


/**
 * Registry of SQL database drivers.
 */
class Drivers extends DatabaseDrivers
{
   public function __construct (DatabaseConfig $Config, Connection $Connection)
   {
      parent::__construct($Config, $Connection);

      if ($Config instanceof Config) {
         $this->register('pgsql', new PostgreSQL($Config, $Connection));
      }
   }
}
