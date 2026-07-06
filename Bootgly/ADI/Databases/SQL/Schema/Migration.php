<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Schema;


use Closure;


/**
 * Configured migration object returned by migration files.
 */
class Migration
{
   // * Config
   public private(set) Closure $Up;
   public private(set) Closure $Down;
   public private(set) string $name;

   // * Data
   // ...

   // * Metadata
   // ...


   public function __construct (Closure $Up, Closure $Down, string $name = '')
   {
      // * Config
      $this->Up = $Up;
      $this->Down = $Down;
      $this->name = $name;
   }

   /**
    * Rename this migration after file discovery.
    */
   public function rename (string $name): self
   {
      $this->name = $name;

      return $this;
   }

   /**
    * Run the upward migration closure.
    */
   public function up (Migrating $Schema): mixed
   {
      return ($this->Up)($Schema);
   }

   /**
    * Run the downward migration closure.
    */
   public function down (Migrating $Schema): mixed
   {
      return ($this->Down)($Schema);
   }
}
