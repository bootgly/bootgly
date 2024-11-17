<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion\Expectations;


use DateTime;
use Exception;

use Bootgly\ACI\Tests\Assertion\Expectation;


class Between implements Expectation
{
   // * Data
   protected int|float|DateTime $min;
   protected int|float|DateTime $max;


   public function __construct (mixed ...$values)
   {
      // * Data
      $this->min = $values[0];
      $this->max = $values[1];

      // @
      if ($this->min > $this->max) {
         throw new Exception('The first value must be less than the second value.');
      }
   }

   public function compare (mixed &$actual, mixed &$expected): bool
   {
      $assertion = ($actual >= $this->min) && ($actual <= $this->max);

      if ($assertion === true) {
         $expected = $actual;
      }

      return $assertion;
   }

   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): array
   {
      // !
      $min = $this->min;
      $max = $this->max;

      return [
         'format' => 'Failed asserting that %s is between %s and %s.',
         'values' => [
            'actual' => $actual,
            'min' => $min,
            'max' => $max
         ]
      ];
   }
}
