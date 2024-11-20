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


class VariadicDirPath implements Matcher
{
   // * Data
   protected string $pattern;

   // * Metadata
   public array $matches {
      get => $this->matches ??= [];
      set => $this->matches = $value;
   }


   public function __construct (string $pattern)
   {
      $this->pattern = $pattern;
   }

   public function compare (mixed &$actual, mixed &$expected): bool
   {
      $pattern = preg_quote(
         str: (string) $expected,
         delimiter: '/'
      );
      $pattern = str_replace(
         search: '\*',
         replace: '.*',
         subject: $pattern
      );

      $result = preg_match(
         pattern: "/^$pattern$/",
         subject: (string) $actual,
         matches: $matches
      );
      $this->matches = $matches;

      return $result === 1;
   }
 
   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): array
   {
      return [
         'format' => 'Failed asserting that %s matches the directory path %s.',
         'values' => [
            'actual' => $actual,
            'expected' => $expected
         ]
      ];
   }
}
