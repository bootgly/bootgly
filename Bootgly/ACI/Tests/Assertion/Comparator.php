<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion;


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
   /**
    * The value to compare `$actual` against, or `Argument::Undefined` when the
    * comparator was built without one.
    *
    * The sentinel is STORED rather than left unassigned: `null` is a legitimate
    * expected value, and `isSet()` — which is what `??` consults — cannot tell
    * an unassigned property from one holding `null`.
    */
   public mixed $expected = Argument::Undefined;


   public function __construct (mixed $expected = Argument::Undefined)
   {
      $this->expected = $expected;
   }

   /**
    * Resolve which expected value this comparison uses: the configured one, or
    * the caller's fallback when the comparator was built without one.
    */
   protected function resolve (mixed $fallback): mixed
   {
      // ?:
      return $this->expected === Argument::Undefined
         ? $fallback
         : $this->expected;
   }
}
