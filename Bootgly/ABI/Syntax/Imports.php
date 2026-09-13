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


use Bootgly\ABI\Syntax\Imports\Analyzer\Result;
use Bootgly\ABI\Syntax\Imports\Formatter;


/**
 * `bootgly lint imports` — the `use` statements of a file: missing, unused,
 * backslash-prefixed and misordered imports. Fixable.
 */
class Imports extends Analyzers
{
   // * Data
   private readonly Imports\Analyzer $Analyzer;
   private readonly Formatter $Formatter;


   public function __construct ()
   {
      $this->Analyzer = new Imports\Analyzer;
      $this->Formatter = new Formatter;
   }

   /**
    * Analyze a PHP file for import violations.
    *
    * @param string $file Absolute path to PHP file
    *
    * @return Result
    */
   public function analyze (string $file): Result
   {
      return $this->Analyzer->analyze($file);
   }

   /**
    * Format the import block of a file based on analysis result.
    *
    * @param Result $Result
    *
    * @return string The corrected source code
    */
   public function format (Result $Result): string
   {
      return $this->Formatter->format($Result);
   }
}
