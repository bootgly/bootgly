<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Benchmark;


use Closure;


class Competitor
{
   // * Data
   /**
    * Display name of the competitor.
    */
   public readonly string $name;

   /**
    * Path to the competitor entry-point script.
    */
   public readonly string $script;

   // * Config
   /**
    * Version string of the competitor.
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
