<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Syntax;


use Bootgly\ABI\Syntax\Imports\Analyzer;
use Bootgly\ABI\Syntax\Imports\Analyzer\Result;
use Bootgly\ABI\Syntax\Imports\Formatter;


class Imports
{
   // * Data
   private readonly Analyzer $Analyzer;
   private readonly Formatter $Formatter;


   public function __construct ()
   {
      $this->Analyzer = new Analyzer;
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
    * @param Result $result
    *
    * @return string The corrected source code
    */
   public function format (Result $result): string
   {
      return $this->Formatter->format($result);
   }
}
