<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Benchmark;


use Closure;


class Opponent
{
   // * Data
   /**
    * Display name of the opponent.
    */
   public readonly string $name;

   /**
    * Path to the opponent entry-point script.
    */
   public readonly string $script;

   // * Config
   /**
    * Version string of the opponent.
    */
   public readonly string $version;

   /**
    * Number of worker processes (server benchmarks only).
    */
   public null|int $workers;


   public function __construct (
      string $name,
      string $script,
      string|Closure $version = '',
      null|int $workers = null,
   )
   {
      // * Data
      $this->name = $name;
      $this->script = $script;
      // * Config
      $this->version = $version instanceof Closure ? $version() : $version;
      $this->workers = $workers;
   }
}
