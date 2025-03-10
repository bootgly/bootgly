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


use Bootgly\ACI\Tests\Asserting\Fallback;
use Bootgly\ACI\Tests\Assertion\Comparator;


class NotIdentical extends Comparator
{
   public function assert (mixed &$actual, mixed &$expected): bool
   {
      $expected = $this->expected ?? $expected;

      return $actual !== $expected;
   }

   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): Fallback
   {
      $expected = $this->expected ?? $expected;

      return new Fallback(
         'Failed asserting that %s is not identical to %s.',
         [
            'actual' => $actual,
            'expected' => $expected
         ],
         $verbosity
      );
   }
}