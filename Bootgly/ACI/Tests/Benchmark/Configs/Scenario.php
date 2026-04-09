<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Benchmark\Configs;


class Scenario
{
   // * Data
   /**
    * Display label for the scenario.
    */
   public readonly string $label;

   /**
    * Group name for categorization.
    */
   public readonly string $group;

   /**
    * Absolute path to the scenario script file.
    */
   public readonly string $file;

   // * Config
   /**
    * Comma-separated competitor names or "all".
    */
   public readonly string $competitors;


   public function __construct (
      string $label,
      string $group,
      string $file,
      string $competitors = 'all',
   )
   {
      $this->label = $label;
      $this->group = $group;
      $this->file = $file;
      $this->competitors = $competitors;
   }
}
