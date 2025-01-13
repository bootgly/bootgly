<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion\Expectations\Behaviors;


use Bootgly\ACI\Tests\Asserting\Fallback;
use Bootgly\ACI\Tests\Assertion\Expectation\Behavior;

/**
 * Validate if $actual is an odd value.
 */
class ValueOdd extends Behavior
{
   public function assert (mixed &$actual, mixed &$expected): bool
   {
      if (is_numeric($actual) === false) {
         return false;
      }

      return $actual % 2 !== 0;
   }

   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): Fallback
   {
      return new Fallback(
         'Failed asserting that %s is an odd value.',
         [
            'actual' => $actual
         ],
         $verbosity
      );
   }
}