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


use Bootgly\ACI\Tests\Asserting\Fallback;
use Bootgly\ACI\Tests\Assertion\Expectation\Finder;


class Contains extends Finder
{
   public function assert (mixed &$actual, mixed &$expected): bool
   {
      $needle = $this->needle ?? $expected;

      if (is_array($actual) === true) {
         return in_array(
            needle: $needle,
            haystack: (array) $actual
         );
      }

      if (
         is_string($actual) === true
         && is_string($needle) === true
      ) {
         return str_contains(
            haystack: $actual,
            needle: $needle
         );
      }

      if (
         (is_object($actual) === true || is_string($actual) === true)
         && is_string($needle) === true
      ) {
         return property_exists(
            object_or_class: $actual,
            property: $needle
         );
      }

      return false;
   }

   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): Fallback
   {
      $type = gettype($actual);
      $needle = $this->needle ?? $expected;

      $template = match ($type) {
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
            'values' => []
         ]
      };

      return new Fallback(
         $template['format'],
         $template['values'],
         $verbosity
      );
   }
}
