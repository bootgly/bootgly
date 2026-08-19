<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion\Expectations\Finders;


use function in_array;
use function is_array;

use Bootgly\ACI\Tests\Asserting\Fallback;
use Bootgly\ACI\Tests\Assertion\Expectation\Finder;


class InArrayValues extends Finder
{
   public function assert (mixed &$actual, mixed &$expected): bool
   {
      $needle = $this->needle;

      if (is_array($actual) === false) {
         return false;
      }

      return in_array($needle, $actual);
   }

   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): Fallback
   {
      $needle = $this->needle;

      return new Fallback(
         'Failed asserting that the array contains the value "%s".',
         [
            'expected' => $needle
         ],
         $verbosity
      );
   }
}
