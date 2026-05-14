<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Builder;


use InvalidArgumentException;

use Bootgly\ADI\Databases\SQL\Builder\Dialects\MySQL;
use Bootgly\ADI\Databases\SQL\Builder\Dialects\PostgreSQL;
use Bootgly\ADI\Databases\SQL\Builder\Dialects\SQLite;


/**
 * Registry of SQL builder dialects.
 */
class Dialects
{
   // * Config
   // ...

   // * Data
   public private(set) MySQL $MySQL;
   public private(set) PostgreSQL $PostgreSQL;
   public private(set) SQLite $SQLite;

   // * Metadata
   // ...


   public function __construct ()
   {
      // * Data
      $this->MySQL = new MySQL;
      $this->PostgreSQL = new PostgreSQL;
      $this->SQLite = new SQLite;
   }

   /**
    * Fetch one SQL builder dialect by driver name.
    */
   public function fetch (string $driver = 'pgsql'): Dialect
   {
      return match ($driver) {
         'mysql', 'mysqli' => $this->MySQL,
         'pgsql', 'postgres', 'postgresql' => $this->PostgreSQL,
         'sqlite', 'sqlite3' => $this->SQLite,
         default => throw new InvalidArgumentException("SQL builder dialect is not registered: {$driver}"),
      };
   }
}
