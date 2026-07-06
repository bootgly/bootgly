<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Benchmark\Configs;


class Load
{
   // * Data
   /**
    * Display label for the load.
    */
   public readonly string $label;

   /**
    * Group name for categorization.
    */
   public readonly string $group;

   /**
    * Absolute path to the load script file.
    */
   public readonly string $file;

   // * Config
   /**
    * Comma-separated opponent names or "all".
    */
   public readonly string $opponents;


   public function __construct (
      string $label,
      string $group,
      string $file,
      string $opponents = 'all',
   )
   {
      $this->label = $label;
      $this->group = $group;
      $this->file = $file;
      $this->opponents = $opponents;
   }
}
