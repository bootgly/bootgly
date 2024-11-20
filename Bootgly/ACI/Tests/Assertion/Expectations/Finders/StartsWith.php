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


use Bootgly\ACI\Tests\Assertion\Expectation\Finder;


class StartsWith implements Finder
{
   // * Data
   public string $needle {
      get => $this->needle ??= null;
      set => $this->needle = $value;
   }


   public function __construct (string ...$needle)
   {
      $this->needle = $needle[0];
   }

   public function compare (mixed &$actual, mixed &$expected): bool
   {
      $needle = $this->needle ?? $expected;

      return strpos(
         haystack: (string) $actual,
         needle: (string) $needle
      ) === 0;
   }

   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): array
   {
      $needle = $this->needle ?? $expected;

      return [
         'format' => 'Failed asserting that the string "%s" starts with "%s".',
         'values' => [
            'actual' => $actual,
            'expected' => $needle
         ]
      ];
   }
}
