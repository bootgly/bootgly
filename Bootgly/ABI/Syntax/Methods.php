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
 * The `methods` lint rule (`Rules::Methods`) — method names that are not one
 * base-form verb: multi-word (camelCase or snake_case), past forms,
 * predicates, plural or third-person forms, nouns and adjectives; Bootgly
 * names methods with a single verb. Check-only.
 */
class Methods extends Analyzers
{
   // * Data
   private readonly Methods\Analyzer $Analyzer;


   public function __construct ()
   {
      $this->Analyzer = new Methods\Analyzer;
   }

   /**
    * Analyze a PHP file for method names that are not one base-form verb.
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
