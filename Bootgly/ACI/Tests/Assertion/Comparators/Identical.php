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


class Identical implements Comparator
{
   public function compare (mixed &$actual, mixed &$expected): bool
   {
      return $actual === $expected;
   }

   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): array
   {
      return [
         'format' => 'Failed asserting that %s is equal to %s.',
         'values' => [
            'actual' => $actual,
            'expected' => $expected
         ]
      ];
   }   
}

const Identical = new Identical();
