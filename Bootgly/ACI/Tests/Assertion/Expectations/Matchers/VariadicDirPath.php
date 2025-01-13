<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion\Expectations\Matchers;


use Bootgly\ACI\Tests\Asserting\Fallback;
use Bootgly\ACI\Tests\Assertion\Expectation\Matcher;


class VariadicDirPath extends Matcher
{
   public function assert (mixed &$actual, mixed &$expected): bool
   {
      // ?
      if (is_string($actual) === false) {
         return false;
      }
      if (is_string($expected) === false) {
         return false;
      }

      // @
      $pattern = $this->pattern ?? $expected;

      $pattern = preg_quote(
         str: $pattern,
         delimiter: '/'
      );
      $pattern = str_replace(
         search: '\*',
         replace: '.*',
         subject: $pattern
      );

      $result = preg_match(
         pattern: "/^$pattern$/",
         subject: $actual,
         matches: $matches
      );
      $this->matches = $matches;

      return $result === 1;
   }
 
   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): Fallback
   {
      $expected = $this->pattern ?? $expected;

      return new Fallback(
         'Failed asserting that %s matches the directory path %s.',
         [
            'actual' => $actual,
            'expected' => $expected
         ],
         $verbosity
      );
   }
}
