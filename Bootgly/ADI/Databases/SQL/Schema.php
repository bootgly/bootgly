<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL;


use BackedEnum;
use Closure;
use Stringable;

use Bootgly\ADI\Databases\SQL\Builder\Dialect as QueryDialect;
use Bootgly\ADI\Databases\SQL\Builder\Dialects\PostgreSQL as SQLPostgreSQL;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Schema\Blueprint;
use Bootgly\ADI\Databases\SQL\Schema\Dialect as SchemaDialect;
use Bootgly\ADI\Databases\SQL\Schema\Dialects;
use Bootgly\ADI\Databases\SQL\Schema\Migrating;


/**
 * SQL schema builder facade for DDL operations.
 */
class Schema implements Migrating
{
   // * Config
   public private(set) SchemaDialect $Dialect;

   // * Data
   // ...

   // * Metadata
   private static null|Dialects $Dialects = null;


   public function __construct (null|QueryDialect|SchemaDialect $Dialect = null)
   {
      // * Config
      if ($Dialect instanceof SchemaDialect) {
         $this->Dialect = $Dialect;

         return;
      }

      $Dialect ??= new SQLPostgreSQL;

      self::$Dialects ??= new Dialects;
      $this->Dialect = self::$Dialects->fetch($Dialect);
   }

   /**
    * Compile one CREATE TABLE statement.
    */
   public function create (BackedEnum|Stringable|string $Table, Closure $Build, bool $exists = false): Query
   {
      $Blueprint = new Blueprint($Table);
      $Build($Blueprint);

      return $this->Dialect->create($Blueprint, $exists);
   }

   /**
    * Compile one ALTER TABLE statement.
    */
   public function alter (BackedEnum|Stringable|string $Table, Closure $Build): Query
   {
      $Blueprint = new Blueprint($Table);
      $Build($Blueprint);

      return $this->Dialect->alter($Blueprint);
   }

   /**
    * Compile one DROP TABLE statement.
    */
   public function drop (BackedEnum|Stringable|string $Table, bool $exists = true): Query
   {
      return $this->Dialect->drop($Table, $exists);
   }

   /**
    * Compile one RENAME TABLE statement.
    */
   public function rename (BackedEnum|Stringable|string $From, BackedEnum|Stringable|string $To): Query
   {
      return $this->Dialect->rename($From, $To);
   }

   /**
    * Compile one CREATE INDEX statement.
    *
    * @param BackedEnum|Stringable|string|array<int,BackedEnum|Stringable|string> $Columns
    */
   public function index (
      BackedEnum|Stringable|string $Table,
      BackedEnum|Stringable|string|array $Columns,
      null|string $name = null,
      bool $unique = false
   ): Query
   {
      $Blueprint = new Blueprint($Table);
      $Index = $Blueprint->index($Columns, $name, $unique);

      return $this->Dialect->index($Index);
   }

   /**
    * Compile one DROP INDEX statement.
    */
   public function unindex (
      BackedEnum|Stringable|string $Table,
      BackedEnum|Stringable|string $Name,
      bool $exists = true
   ): Query
   {
      return $this->Dialect->unindex($Table, $Name, $exists);
   }

   /**
    * Compile one DROP CONSTRAINT statement.
    */
   public function unconstrain (
      BackedEnum|Stringable|string $Table,
      BackedEnum|Stringable|string $Name,
      bool $exists = true
   ): Query
   {
      return $this->Dialect->unconstrain($Table, $Name, $exists);
   }
}
