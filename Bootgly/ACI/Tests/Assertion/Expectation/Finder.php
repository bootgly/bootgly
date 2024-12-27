<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion\Expectation;


use Bootgly\ACI\Tests\Asserting;


/**
 * Finder are for assertions that find a needle ($expected) in a haystack ($actual).
 * 
 * Use both $actual and $expected as input in the assertion.
 * The $expected is a needle to find in $actual.
 * 
 * No output.
 */
abstract class Finder implements Asserting
{
   // * Config
   public mixed $needle {
      get => $this->needle ??= null;
      set => $this->needle = $value;
   }


   public function __construct (mixed $needle)
   {
      $this->needle = $needle;
   }
}
