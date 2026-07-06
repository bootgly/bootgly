<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion\Expectations\Matchers;


use function is_string;
use function preg_match;
use function preg_quote;
use function str_replace;

use Bootgly\ACI\Tests\Asserting\Fallback;
use Bootgly\ACI\Tests\Assertion\Expectation\Matcher;


class VariadicDirPath extends Matcher
{
   public function assert (mixed &$actual, mixed &$expected): bool
   {
      // * Config
      // ($expected is the Matcher itself when passed as `expected:` — the
      //  pattern set at construction takes precedence)
      $pattern = $this->pattern ?? $expected;

      // ?
      if (is_string($actual) === false) {
         return false;
      }
      if (is_string($pattern) === false) {
         return false;
      }

      // @

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
