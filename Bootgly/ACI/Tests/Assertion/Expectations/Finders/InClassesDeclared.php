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


use function class_exists;
use function is_string;

use Bootgly\ACI\Tests\Asserting\Fallback;
use Bootgly\ACI\Tests\Assertion\Expectation\Finder;


class InClassesDeclared extends Finder
{
   public function assert (mixed &$actual, mixed &$expected): bool
   {
      $needle = $this->needle;

      if (is_string($needle) === false) {
         return false;
      }

      return class_exists($needle);
   }

   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): Fallback
   {
      $needle = $this->needle;

      return new Fallback(
         'Failed asserting that the class "%s" is declared.',
         [
            'expected' => $needle
         ],
         $verbosity
      );
   }
}
