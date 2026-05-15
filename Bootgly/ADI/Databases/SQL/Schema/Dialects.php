<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Schema;


use InvalidArgumentException;

use Bootgly\ADI\Databases\SQL\Builder\Dialect as QueryDialect;
use Bootgly\ADI\Databases\SQL\Builder\Dialects\MySQL as SQLMySQL;
use Bootgly\ADI\Databases\SQL\Builder\Dialects\PostgreSQL as SQLPostgreSQL;
use Bootgly\ADI\Databases\SQL\Builder\Dialects\SQLite as SQLSQLite;
use Bootgly\ADI\Databases\SQL\Schema\Dialect;
use Bootgly\ADI\Databases\SQL\Schema\Dialects\MySQL;
use Bootgly\ADI\Databases\SQL\Schema\Dialects\PostgreSQL;
use Bootgly\ADI\Databases\SQL\Schema\Dialects\SQLite;


/**
 * Registry of schema DDL dialect compilers.
 */
class Dialects
{
   /**
    * Fetch the matching schema dialect for one SQL builder dialect.
    */
   public function fetch (QueryDialect $Dialect): Dialect
   {
      if ($Dialect instanceof SQLMySQL) {
         return new MySQL($Dialect);
      }

      if ($Dialect instanceof SQLPostgreSQL) {
         return new PostgreSQL($Dialect);
      }

      if ($Dialect instanceof SQLSQLite) {
         return new SQLite($Dialect);
      }

      throw new InvalidArgumentException('Schema Builder dialect is not registered.');
   }
}
