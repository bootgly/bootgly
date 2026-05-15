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


use BackedEnum;
use Closure;
use Stringable;

use Bootgly\ADI\Databases\SQL\Builder\Query;


/**
 * Schema surface exposed to migration files.
 */
interface Migrating
{
   /**
    * Compile one CREATE TABLE statement.
    */
   public function create (BackedEnum|Stringable|string $Table, Closure $Build, bool $exists = false): Query;

   /**
    * Compile one ALTER TABLE statement.
    */
   public function alter (BackedEnum|Stringable|string $Table, Closure $Build): Query;

   /**
    * Compile one DROP TABLE statement.
    */
   public function drop (BackedEnum|Stringable|string $Table, bool $exists = true): Query;

   /**
    * Compile one RENAME TABLE statement.
    */
   public function rename (BackedEnum|Stringable|string $From, BackedEnum|Stringable|string $To): Query;

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
   ): Query;

   /**
    * Compile one DROP INDEX statement.
    */
   public function unindex (
      BackedEnum|Stringable|string $Table,
      BackedEnum|Stringable|string $Name,
      bool $exists = true
   ): Query;

   /**
    * Compile one DROP CONSTRAINT statement.
    */
   public function unconstrain (
      BackedEnum|Stringable|string $Table,
      BackedEnum|Stringable|string $Name,
      bool $exists = true
   ): Query;
}
