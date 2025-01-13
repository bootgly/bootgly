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


class InArrayKeys extends Finder
{
   public function assert (mixed &$actual, mixed &$expected): bool
   {
      if (
         is_int($expected) === false
         && is_float($expected) === false
         && is_string($expected) === false
         && is_bool($expected) === false
         && is_resource($expected) === false
         && $expected !== null
      ) {
         return false;
      }

      if (is_array($actual) === false) {
         return false;
      }

      return array_key_exists($expected, $actual); // @phpstan-ignore-line
   }

   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): Fallback
   {
      return new Fallback(
         'Failed asserting that the array has the key "%s".',
         [
            'expected' => $expected
         ],
         $verbosity
      );
   }
}
