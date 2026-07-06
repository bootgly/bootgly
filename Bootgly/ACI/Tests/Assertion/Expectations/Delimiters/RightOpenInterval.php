<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion\Expectations\Delimiters;


use Bootgly\ACI\Tests\Asserting\Fallback;
use Bootgly\ACI\Tests\Assertion\Expectation\Delimiter;


class RightOpenInterval extends Delimiter
{
   public function assert (mixed &$actual, mixed &$expected): bool
   {
      $assertion = ($actual >= $this->min) && ($actual < $this->max);

      if ($assertion === true) {
         $expected = $actual;
      }

      return $assertion;
   }

   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): Fallback
   {
      // !
      $min = $this->min;
      $max = $this->max;

      return new Fallback(
         'Failed asserting that %s is in right-open interval [%s, %s).',
         [
            'actual' => $actual,
            'min' => $min,
            'max' => $max
         ],
         $verbosity
      );
   }
}