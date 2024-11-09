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

   public function fail (mixed $actual, mixed $expected): array
   {
      return [
         'format' => 'Failed asserting that %s is not equal to %s.',
         'values' => [
            'actual' => $actual,
            'expected' => $expected
         ]
      ];
   }
}
