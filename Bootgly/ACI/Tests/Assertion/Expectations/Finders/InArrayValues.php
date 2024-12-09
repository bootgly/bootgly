<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion\Expectations\Finders;


use Bootgly\ACI\Tests\Asserting\Fallback;
use Bootgly\ACI\Tests\Assertion\Expectation\Finder;


class InArrayValues extends Finder
{
   public function assert (mixed &$actual, mixed &$expected): bool
   {
      return in_array($expected, $actual);
   }

   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): Fallback
   {
      return new Fallback(
         'Failed asserting that the array contains the value "%s".',
         [
            'expected' => $expected
         ],
         $verbosity
      );
   }
}
