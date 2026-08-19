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


use function array_key_exists;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_resource;
use function is_string;

use Bootgly\ACI\Tests\Asserting\Fallback;
use Bootgly\ACI\Tests\Assertion\Expectation\Finder;


class InArrayKeys extends Finder
{
   public function assert (mixed &$actual, mixed &$expected): bool
   {
      $needle = $this->needle;

      if (
         is_int($needle) === false
         && is_float($needle) === false
         && is_string($needle) === false
         && is_bool($needle) === false
         && is_resource($needle) === false
         && $needle !== null
      ) {
         return false;
      }

      if (is_array($actual) === false) {
         return false;
      }

      return array_key_exists($needle, $actual); // @phpstan-ignore-line
   }

   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): Fallback
   {
      $needle = $this->needle;

      return new Fallback(
         'Failed asserting that the array has the key "%s".',
         [
            'expected' => $needle
         ],
         $verbosity
      );
   }
}
