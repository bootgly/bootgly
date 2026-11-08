<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Syntax;


use Bootgly\ABI\Syntax\Analyzers\Result;


/**
 * The `casing` lint rule (`Rules::Casing`) — Object, object-collection and acronym names of properties, parameters and variables. Check-only.
 */
class Casing extends Analyzers
{
   // * Data
   private readonly Casing\Analyzer $Analyzer;


   public function __construct ()
   {
      $this->Analyzer = new Casing\Analyzer;
   }

   /**
    * Analyze a PHP file for casing violations.
    *
    * @param string $file Absolute path to the PHP file
    *
    * @return Result
    */
   public function analyze (string $file): Result
   {
      return $this->Analyzer->analyze($file);
   }
}
