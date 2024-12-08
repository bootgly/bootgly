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


use Bootgly\ACI\Tests\Assertion\Auxiliaries\Comparator;


/**
 * @property mixed $expectation
 */
trait Comparators
{
   /**
    * Compare the $actual value.
    * "expect that $actual compare $comparator $expected".
    * The $actual, $comparator and $expected are used in the assertion.
    *
    * @param Comparator|null $comparator The comparator to use.
    * @param mixed $expected The expected value to compare against.
    *
    * @return self Returns the current instance for method chaining.
    */
   public function compare (?Comparator $comparator = null, mixed $expected): self
   {
      $this->expectation = match ($comparator) {
         Comparator::Equal =>
            new Comparators\Equal($expected),
         Comparator::NotEqual =>
            new Comparators\NotEqual($expected),

         Comparator::Identical =>
            new Comparators\Identical($expected),
         Comparator::NotIdentical =>
            new Comparators\NotIdentical($expected),

         Comparator::GreaterThan =>
            new Comparators\GreaterThan($expected),
         Comparator::LessThan =>
            new Comparators\LessThan($expected),

         Comparator::GreaterThanOrEqual =>
            new Comparators\GreaterThanOrEqual($expected),
         Comparator::LessThanOrEqual =>
            new Comparators\LessThanOrEqual($expected),

         default =>
            new Comparators\Identical($expected)
      };

      return $this;
   }
}