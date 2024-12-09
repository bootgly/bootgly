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


class StartsWith extends Finder
{
   public function assert (mixed &$actual, mixed &$expected): bool
   {
      $needle = $this->needle ?? $expected;

      return strpos(
         haystack: (string) $actual,
         needle: (string) $needle
      ) === 0;
   }

   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): Fallback
   {
      $needle = $this->needle ?? $expected;

      return new Fallback(
         'Failed asserting that the string "%s" starts with "%s".',
         [
            'actual' => $actual,
            'expected' => $needle
         ],
         $verbosity
      );
   }
}
