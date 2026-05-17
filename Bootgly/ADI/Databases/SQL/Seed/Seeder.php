<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Seed;


use Closure;

use Bootgly\ADI\Databases\SQL as SQLDatabase;
use Bootgly\ADI\Databases\SQL\Seed;


/**
 * Configured SQL seeder object returned by seeder files.
 */
class Seeder
{
   // * Config
   public private(set) Closure $Run;
   public private(set) string $name;

   // * Data
   // ...

   // * Metadata
   // ...


   public function __construct (Closure $Run, string $name = '')
   {
      // * Config
      $this->Run = $Run;
      $this->name = $name;
   }

   /**
    * Name this seeder after file discovery.
    */
   public function name (string $name): void
   {
      $this->name = $name;
   }

   /**
    * Run the configured seeder closure.
    */
   public function run (SQLDatabase $Database, Seed $Seed): mixed
   {
      return ($this->Run)($Database, $Seed);
   }
}
