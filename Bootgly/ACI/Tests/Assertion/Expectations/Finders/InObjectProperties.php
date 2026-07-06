<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion\Expectations\Finders;


use function is_object;
use function is_string;
use function property_exists;

use Bootgly\ACI\Tests\Asserting\Fallback;
use Bootgly\ACI\Tests\Assertion\Expectation\Finder;


class InObjectProperties extends Finder
{
   public function assert (mixed &$actual, mixed &$expected): bool
   {
      if (
         is_object($actual) === false
         && is_string($actual) === false
      ) {
         return false;
      }
      if (is_string($expected) === false) {
         return false;
      }

      return property_exists($actual, $expected);
   }

   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): Fallback
   {
      return new Fallback(
         'Failed asserting that the object has the property "%s".',
         [
            'expected' => $expected
         ],
         $verbosity
      );
   }
}
