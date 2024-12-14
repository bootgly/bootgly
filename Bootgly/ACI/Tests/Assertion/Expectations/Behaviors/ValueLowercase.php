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
 * Validate if $actual is a lowercase string.
 */
class ValueLowercase extends Behavior
{
   public function assert (mixed &$actual, mixed &$expected): bool
   {
      return is_string($actual) && strtolower($actual) === $actual;
   }

   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): Fallback
   {
      return new Fallback(
         'Failed asserting that %s is a lowercase string.',
         [
            'actual' => $actual
         ],
         $verbosity
      );
   }
}