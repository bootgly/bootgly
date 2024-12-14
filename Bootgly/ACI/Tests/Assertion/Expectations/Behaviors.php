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


use Bootgly\ACI\Tests\Asserting;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Type;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Value;
use Bootgly\ACI\Tests\Assertion\Comparators;


/**
 * @property mixed $expectation
 */
trait Behaviors
{
   /**
    * Validate the $actual value...
    * "expect that $actual to be $expected".
    * Only the $actual is used in the assertion when using Auxiliaries\Type or Auxiliaries\Value, otherwise the $expected is used.
    *
    * @param mixed $expected The expected value to assert against.
    *
    * @return self Returns the current instance for method chaining.
    */
   public function be (mixed $expected): self
   {
      $namespace = Behaviors::class;
      $class = $expected->name;

      $this->set(match (true) {
         $expected instanceof Type
            => new ("{$namespace}\Type{$class}"),
         $expected instanceof Value
            => new ("{$namespace}\Value{$class}"),

         $expected instanceof Asserting
            => $expected,

         default => new Comparators\Identical($expected)
      });

      return $this;
   }
}
