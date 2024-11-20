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


class Contains implements Finder
{
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
      $type = gettype($actual);
      $needle = $this->needle ?? $expected;

      return match ($type) {
         'array' => in_array(
            needle: $needle,
            haystack: (array) $actual
         ),
         'string' => str_contains(
            haystack: (string) $actual,
            needle: (string) $needle
         ),
         'object' => property_exists(
            object_or_class: $actual,
            property: $needle
         ),
         default => false
      };
   }

   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): array
   {
      $type = gettype($actual);
      $needle = $this->needle ?? $expected;

      return match ($type) {
         'array' => [
            'format' => "Failed asserting that the array contains \"%s\".",
            'values' => [
               'expected' => $needle
            ]
         ],
         'string' => [
            'format' => "Failed asserting that the string contains \"%s\".",
            'values' => [
               'expected' => $needle
            ]
         ],
         'object' => [
            'format' => "Failed asserting that the object contains the property \"%s\".",
            'values' => [
               'expected' => $needle
            ]
         ],
         default => [
            'format' => "Cannot assert that the `actual` contains `expected`.",
         ]
      };
   }
}
