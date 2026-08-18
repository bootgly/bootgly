<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Suite;


use Closure;

use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Fixture;
use Bootgly\ACI\Tests\Suite\Test\Separator;


class Test
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
   /**
    * Fixture orchestrating per-case state.
    *
      * When set, Tester::pretest() calls $Fixture->prepare() before the test
      * closure, and Tester::postest() calls $Fixture->dispose() after.
    * Lifecycle is idempotent — runners that need state earlier (e.g. WPI
    * E2E) may invoke prepare() ahead of time without conflict.
    */
   public null|Fixture $Fixture;

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
   /**
    * The base name of the file this test case was resolved from.
    *
    * The runner used to take it from the internal array pointer of the Suite's
    * test list, which desynchronises from the executed cases as soon as one is
    * skipped — so the name travels with the case instead.
    */
   public private(set) null|string $file = null;


   public function __construct (
      // * Data (required)
      Assertions|Closure $test,
      // * Config (optional)
      null|string $description = null,
      null|Separator $Separator = null,
      bool $skip = false,
      bool $ignore = false,
      null|Closure $retest = null,
      null|Fixture $Fixture = null,
   )
   {
      // * Config
      $this->description = $description;
      $this->Separator = $Separator ?? new Separator;
      $this->skip = $skip;
      $this->ignore = $ignore;
      $this->retest = $retest;
      $this->Fixture = $Fixture;

      // * Data
      $this->test = $test;
   }

   /**
    * Index this Test in the Suite.
    *
    * @param int $case The test case index.
    * @param null|true $last Whether this is the last test case.
    * @param null|string $file The base name of the file it was resolved from.
    */
   public function index (int $case, null|true $last = null, null|string $file = null): void
   {
      // * Metadata
      $this->case = $case;
      $this->last = $last ?? $this->last;
      $this->file = $file ?? $this->file;
   }
}

