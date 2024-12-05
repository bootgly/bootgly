<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion\Expectations\Comparators;


use Bootgly\ACI\Tests\Asserting\Fallback;
use Bootgly\ACI\Tests\Assertion\Expectation\Comparator;


class NotEqual extends Comparator
{
   public function assert (mixed &$actual, mixed &$expected): bool
   {
      $expected = $this->expected ?? $expected;

      return $actual != $expected;
   }

   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): Fallback
   {
      $expected = $this->expected ?? $expected;

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

      return new Fallback(
         'Failed asserting that %s is not equal to %s.',
         $template['values'],
         $verbosity
      );
   }
}
