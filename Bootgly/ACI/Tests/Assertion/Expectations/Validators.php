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


use AssertionError;

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Type;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Value;

/**
 * @property mixed $expectation
 */
trait Validators
{
   /**
    * Validate the $actual value.
    * "expect that $actual validate $validator".
    * Only the $actual is used in the assertion.
    *
    * @param Type|Value $validator The validator to use.
    *
    * @return self Returns the current instance for method chaining.
    */
   public function validate (Type|Value $validator): self
   {
      $namespace = Validators::class;
      $class = $validator->name;

      $this->expectation = match (true) {
         $validator instanceof Type
            => new ("{$namespace}\Type{$class}"),
         $validator instanceof Value
            => new ("{$namespace}\Value{$class}"),
         default => throw new AssertionError('Invalid validator.')
      };

      return $this;
   }
}
