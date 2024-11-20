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


use Bootgly\ACI\Tests\Assertion\Expectation\Matcher;


class RegexMatch implements Matcher
{
   // * Data
   protected string $pattern;

   // * Metadata
   public array $matches {
      get => $this->matches ??= [];
      set => $this->matches = $value;
   }


   public function __construct (string ...$pattern)
   {
      $this->pattern = $pattern[0];
   }

   public function compare (mixed &$actual, mixed &$expected): bool
   {
      // * Data
      $pattern = $this->pattern ?? $expected;
      // * Metadata
      $matches = fn () => $this->matches;

      $result = preg_match(
         pattern: (string) $pattern,
         subject: (string) $actual,
         matches: $matches
      ) === 1;

      $this->matches = $matches;

      return $result;
   }

   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): array
   {
      return [
         'format' => 'Failed asserting that %s matches the regex %s.',
         'values' => [
            'actual' => $actual,
            'expected' => $expected
         ]
      ];
   }
}
