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
use Throwable;


/**
 * @property mixed $expectation
 */
trait Throwers
{
   /**
    * Throw an exception.
    * "expect that $actual throw $expected".
    * The $actual must be a callable.
    *
    * @param string|Throwable $expected The expected exception message or Throwable.
    *
    * @return self Returns the current instance for method chaining.
    */
   public function throw (string|Throwable $expected): self
   {
      if (!is_callable($this->actual)) {
         throw new AssertionError('The actual value must be a callable.');
      }

      $this->expectation = new Throwers\ThrowException($expected);

      return $this;
   }
}
