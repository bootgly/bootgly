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


use DateTime;
use Exception;

use Bootgly\ACI\Tests\Asserting;


/**
 * Delimiter are for assertions that $actual must be between $expected ($from, $to).
 * 
 * Use both $actual and $expected ($min, $max) as input in the assertion.
 * The $expected is the range ($min, $max) to validate with $actual.
 * 
 * No output.
 */
abstract class Delimiter implements Asserting
{
   // * Config
   public int|float|DateTime $min;
   public int|float|DateTime $max;

   /**
    * Create a new ClosedInterval instance.
    * First value must be less than the second value.
    * 
    * @param int|float|DateTime $min
    * @param int|float|DateTime $max
    *
    * @throws Exception
    */
   public function __construct (int|float|DateTime $min, int|float|DateTime $max)
   {
      // * Data
      $this->min = $min;
      $this->max = $max;

      // @
      if ($this->min > $this->max) {
         throw new Exception('The first value must be less than the second value.');
      }
   }
}
