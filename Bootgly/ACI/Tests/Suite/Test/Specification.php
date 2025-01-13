<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Suite\Test;


use Closure;
use Exception;

use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;


class Specification // in context of Suite
{
   // * Config
   /**
    * The test case description.
    */
   public null|string $description = null;
   /**
    * The Separator configuration.
    */
   public Separator $Separator;
   /**
    * Indicates if the test case should be ignored.
    * Skip without output (used to skip with command arguments)
    */
   public null|bool $ignore = null;
   /**
    * The retest Closure.
    */
   public null|Closure $retest = null;

   // * Data
   /**
    * The test case Closure (Basic API) or Assertions instance (Advanced API).
    */
   public Assertions|Closure $test;

   // * Metadata
   /**
    * The test case index + 1.
    */
   public null|int $case = null;
   /**
    * Indicates if the test case was retested.
    */
   public null|bool $retested = null;
   /**
    * Indicates if the test case is the last one.
    */
   public null|true $last = null;


   /**
    * Specification constructor.
    * 
    * @param array<string,mixed> $specification
    */
   public function __construct (array $specification)
   {
      // !
      // * Config (optional User Input)
      // $description
      $description = $specification['describe'] ?? $specification['description'] ?? null;
      if (
         $description !== null
         && is_string($description) === false
      ) {
         throw new Exception('Description only accepts string');
      }
      // $Separator
      $separator_line = $specification['separator.line'] ?? null;
      if (
         $separator_line !== null
         && (
            $separator_line !== true
            && is_string($separator_line) === false
         )
      ) {
         throw new Exception('Separator line only accepts boolean or string');
      }
      $separator_left = $specification['separator.left'] ?? null;
      if (
         $separator_left !== null
         && is_string($separator_left) === false
      ) {
         throw new Exception('Separator left only accepts string');
      }
      $separator_header = $specification['separator.header'] ?? null;
      if (
         $separator_header !== null
         && is_string($separator_header) === false
      ) {
         throw new Exception('Separator header only accepts string');
      }
      // $ignore
      $ignore = $specification['ignore'] ?? null;
      if (
         $ignore !== null
         && is_bool($ignore) === false
      ) {
         throw new Exception('Ignore only accepts boolean');
      }
      // $retest
      $retest = $specification['retest'] ?? null;
      if (
         $retest !== null
         && $retest instanceof Closure === false
      ) {
         throw new Exception('Retest only accepts Closure');
      }

      // * Data (required User Input)
      // $test
      $test = $specification['test']
         ?? throw new Exception('Test case not defined');
      if ($test instanceof Closure === false && $test instanceof Assertions === false) {
         throw new Exception('Test case only accepts Closure or Assertions instance');
      }

      // * Metadata (Internal Use)
      // $case
      /** @var null|int $case */
      $case = $specification['case'] ?? null;
      // $retested
      /** @var null|bool $retested */
      $retested = $specification['retested'] ?? null;
      // $last
      /** @var null|true $last */
      $last = $specification['last'] ?? null;

      // ---

      // @
      // * Config
      // $description
      $this->description = $description;
      // $Separator
      $this->Separator = new Separator;
      $this->Separator->line = $separator_line;
      $this->Separator->left = $separator_left;
      $this->Separator->header = $separator_header;
      // $ignore
      $this->ignore = $ignore;
      // $retest
      $this->retest = $retest;

      // * Data
      $this->test = $test;

      // * Metadata
      $this->case = $case;
      $this->retested = $retested;
      $this->last = $last;
   }
}

