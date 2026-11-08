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
 * The `sections` lint rule (`Rules::Sections`) — Config/Data/Metadata property sections: order, membership and visibility. Check-only.
 */
class Sections extends Analyzers
{
   // * Data
   private readonly Sections\Analyzer $Analyzer;


   public function __construct ()
   {
      $this->Analyzer = new Sections\Analyzer;
   }

   /**
    * Analyze a PHP file for sections violations.
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
