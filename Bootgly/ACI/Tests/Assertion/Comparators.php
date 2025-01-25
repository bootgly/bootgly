<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion;


use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;


/**
 * @property mixed $expectation
 */
trait Comparators // @phpstan-ignore-line
{
   /**
    * Compare the $actual value.
    * "expect that $actual compare $comparator $expected".
    * The $actual, $comparator and $expected are used in the assertion.
    *
    * @param Op|null $comparator The comparator to use.
    * @param mixed $expected The expected value to compare against.
    *
    * @return self Returns the current instance for method chaining.
    */
   public function compare (?Op $comparator = null, mixed $expected): self
   {
      $expectation = match ($comparator) {
         Op::Equal =>
            new Comparators\Equal($expected),
         Op::NotEqual =>
            new Comparators\NotEqual($expected),

         Op::Identical =>
            new Comparators\Identical($expected),
         Op::NotIdentical =>
            new Comparators\NotIdentical($expected),

         Op::GreaterThan =>
            new Comparators\GreaterThan($expected),
         Op::LessThan =>
            new Comparators\LessThan($expected),

         Op::GreaterThanOrEqual =>
            new Comparators\GreaterThanOrEqual($expected),
         Op::LessThanOrEqual =>
            new Comparators\LessThanOrEqual($expected),

         default =>
            new Comparators\Identical($expected)
      };

      $this->push($expectation);

      return $this;
   }
}
