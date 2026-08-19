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


use function is_string;
use function trait_exists;

use Bootgly\ACI\Tests\Asserting\Fallback;
use Bootgly\ACI\Tests\Assertion\Expectation\Finder;


class InTraitsDeclared extends Finder
{
   public function assert (mixed &$actual, mixed &$expected): bool
   {
      $needle = $this->needle;

      if (is_string($needle) === false) {
         return false;
      }

      return trait_exists($needle);
   }

   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): Fallback
   {
      $needle = $this->needle;

      return new Fallback(
         'Failed asserting that the trait "%s" is declared.',
         [
            'expected' => $needle
         ],
         $verbosity
      );
   }
}
