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

use Bootgly\ACI\Tests\Asserting\Actual;
use Bootgly\ACI\Tests\Assertion\Expectation;


trait Callers
{
   use Actual;
   use Expectation;


   // * Config
   /**
    * @var array<mixed> $arguments
    */
   protected array $arguments;


   /**
    * Configure the $actual (callable) $arguments to call.
    * "expect that $actual (callable) call with $arguments...".
    * The $actual (callable) and $arguments are used in other assertions.
    *
    * @param mixed ...$arguments The arguments to call the $actual (callable).
    *
    * @return self Returns the current instance for method chaining.
    */
   public function call (mixed ...$arguments): self
   {
      $this->arguments = $arguments;

      $callable = $this->actual;

      // ? Check if the callable is not a Closure
      if (
         is_array($callable) === true
         && isSet($callable[0]) === true
         && isSet($callable[1]) === true
         && (
            is_object($callable[0]) === true
            || is_string($callable[0]) === true
         )
         && is_string($callable[1]) === true
         && method_exists($callable[0], $callable[1]) === true
      ) {
         throw new AssertionError('You need to use First class callable syntax: `$Object->$method(...)`.');
      }

      new Callers\CallClosure($arguments);

      return $this;
   }
}
