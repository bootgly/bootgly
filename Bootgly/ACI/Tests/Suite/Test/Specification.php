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

use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;


class Specification
{
   // * Config
   /**
    * The test case description.
    */
   public null|string $description;
   /**
    * The Separator configuration.
    */
   public Separator $Separator;
   /**
    * Indicates if the test case should be skipped.
    */
   public bool $skip;
   /**
    * Indicates if the test case should be ignored.
    * Skip without output (used to skip with command arguments)
    */
   public bool $ignore;
   /**
    * The retest Closure.
    */
   public null|Closure $retest;

   // * Data
   /**
    * The test case Closure (Basic API) or Assertions instance (Advanced API).
    */
   public Assertions|Closure $test;

   // * Metadata
   /**
    * The test case index + 1.
    */
   public private(set) null|int $case = null;
   /**
    * Indicates if the test case is the last one.
    */
   public private(set) null|true $last = null;


   public function __construct (
      // * Data (required)
      Assertions|Closure $test,
      // * Config (optional)
      null|string $description = null,
      null|Separator $Separator = null,
      bool $skip = false,
      bool $ignore = false,
      null|Closure $retest = null,
   )
   {
      // * Config
      $this->description = $description;
      $this->Separator = $Separator ?? new Separator;
      $this->skip = $skip;
      $this->ignore = $ignore;
      $this->retest = $retest;

      // * Data
      $this->test = $test;
   }

   /**
    * Index this Specification in the Suite.
    *
    * @param int $case The test case index.
    * @param null|true $last Whether this is the last test case.
    */
   public function index (int $case, null|true $last = null): void
   {
      // * Metadata
      $this->case = $case;
      $this->last = $last ?? $this->last;
   }
}

