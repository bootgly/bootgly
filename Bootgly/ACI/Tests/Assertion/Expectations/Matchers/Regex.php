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


class Regex extends Matcher
{
   public function assert (mixed &$actual, mixed &$expected): bool
   {
      // ?
      if (is_string($expected) === false) {
         return false;
      }
      if (is_string($actual) === false) {
         return false;
      }

      // @
      // * Config
      $pattern = $this->pattern ?? $expected;
      $matches = fn (): array => $this->matches;

      $result = preg_match(
         pattern: $pattern,
         subject: $actual,
         matches: $matches
      ) === 1;

      $this->matches = $matches;

      return $result;
   }

   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): Fallback
   {
      $expected = $this->pattern ?? $expected;

      return new Fallback(
         'Failed asserting that %s matches the regex %s.',
         [
            'actual' => $actual,
            'expected' => $expected
         ],
         $verbosity
      );
   }
}
