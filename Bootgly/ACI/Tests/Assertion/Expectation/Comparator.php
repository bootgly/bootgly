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


use Bootgly\ABI\Argument;
use Bootgly\ACI\Tests\Asserting;


/**
 * Comparators are for assertions that require a comparison between $actual and $expected.
 * 
 * Use both $actual and $expected in the assertion.
 * The $expected is the value to compare with $actual.
 * 
 * Uses simple one-sided comparison operators.
 * e.g. $actual > $expected, $actual < $expected, $actual === $expected...
 */
abstract class Comparator implements Asserting
{
   // * Config
   public mixed $expected;

   public function __construct (mixed $expected = Argument::Undefined)
   {
      if ($expected !== Argument::Undefined) {
         $this->expected = $expected;
      }
   }
}
