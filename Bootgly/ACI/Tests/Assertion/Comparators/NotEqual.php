<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion\Comparators;


use Bootgly\ACI\Tests\Assertion\Comparator;


class NotEqual implements Comparator
{
   public function compare (mixed &$actual, mixed &$expected): bool
   {
      return $actual !== $expected;
   }

   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): array
   {
      // verbosity 1
      switch ($verbosity) {
         case 1:
            if (is_array($actual))
               $actual = json_encode($actual);
            if (is_array($expected))
               $expected = json_encode($expected);
            break;
         case 2:
            if (is_object($actual))
               $actual = serialize($actual);
            if (is_object($expected))
               $expected = serialize($expected);
            break;
      }

      $template = [
         'format' => 'Failed asserting that %s is not equal to %s.',
      ];
      $template['values'] = match ($verbosity) {
         0 => self::FALLBACK_TEMPLATE_VALUES_0,
         default => [
               'actual' => $actual,
               'expected' => $expected
            ],
      };

      return $template;
   }
}
