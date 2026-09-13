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
use Bootgly\ABI\Syntax\Nullables\Formatter;


/**
 * `bootgly lint nullables` — the nullable shorthand `?T` in parameter, return
 * and property types; Bootgly writes `null|T`. Fixable.
 */
class Nullables extends Analyzers
{
   // * Data
   private readonly Nullables\Analyzer $Analyzer;
   private readonly Formatter $Formatter;


   public function __construct ()
   {
      $this->Analyzer = new Nullables\Analyzer;
      $this->Formatter = new Formatter;
   }

   /**
    * Analyze a PHP file for nullable shorthands.
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
    * Rewrite every reported `?T` as `null|T`.
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
