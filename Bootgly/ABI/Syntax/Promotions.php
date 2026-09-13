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
 * `bootgly lint promotions` — constructor property promotion; Bootgly declares
 * properties explicitly and assigns them in the constructor body. Check-only.
 */
class Promotions extends Analyzers
{
   // * Data
   private readonly Promotions\Analyzer $Analyzer;


   public function __construct ()
   {
      $this->Analyzer = new Promotions\Analyzer;
   }

   /**
    * Analyze a PHP file for promoted constructor properties.
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
