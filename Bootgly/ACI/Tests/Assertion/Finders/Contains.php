<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion\Finders;


use Bootgly\ACI\Tests\Assertion\Finder;


class Contains implements Finder
{
   public mixed $needle {
      get => $this->needle ??= null;
      set => $this->needle = $value;
   }


   public function __construct (mixed ...$values)
   {
      $this->needle = $values[0];
   }
   public function compare (mixed &$actual, mixed &$expected): bool
   {
      $type = gettype($actual);

      return match ($type) {
         'array' => in_array(
            needle: $this->needle,
            haystack: (array) $actual
         ),
         'string' => str_contains(
            haystack: (string) $actual,
            needle: (string) $this->needle
         ),
         'object' => property_exists(
            object_or_class: $actual,
            property: $this->needle
         ),
         default => false
      };
   }

   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): array
   {
      $type = gettype($actual);

      return match ($type) {
         'array' => [
            'format' => "Failed asserting that the array contains \"%s\".",
            'values' => [
               'expected' => $this->needle
            ]
         ],
         'string' => [
            'format' => "Failed asserting that the string contains \"%s\".",
            'values' => [
               'expected' => $this->needle
            ]
         ],
         'object' => [
            'format' => "Failed asserting that the object contains the property \"%s\".",
            'values' => [
               'expected' => $this->needle
            ]
         ],
         default => [
            'format' => "Cannot assert that the `actual` contains `expected`.",
         ]
      };
   }
}
