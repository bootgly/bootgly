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
use Bootgly\ABI\Syntax\Spacing\Formatter;


/**
 * The `spacing` lint rule (`Rules::Spacing`) — One space before a declaration's `(`, none before a call's. Fixable.
 */
class Spacing extends Analyzers
{
   // * Data
   private readonly Spacing\Analyzer $Analyzer;
   private readonly Formatter $Formatter;


   public function __construct ()
   {
      $this->Analyzer = new Spacing\Analyzer;
      $this->Formatter = new Formatter;
   }

   /**
    * Analyze a PHP file for spacing violations.
    *
    * @param string $file Absolute path to the PHP file
    *
    * @return Result
    */
   public function analyze (string $file): Result
   {
      return $this->Analyzer->analyze($file);
   }

   /**
    * Rewrite the gap before every reported parameter or argument list.
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
