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
#use Bootgly\ACI\Tests\Assertion\Expectation\Caller;
#use Bootgly\ACI\Tests\Assertion\Expectation\Thrower;


/**
 * @property mixed $actual
 * 
 * @property mixed $expectation
 */
trait Callers // (WIP)
{
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
      $callable = $this->actual;

      if (is_callable($callable) === false) {
         throw new AssertionError('$actual is not callable.');
      }

      $this->arguments = $arguments;
      #$this->expecting = [Caller::class, Thrower::class];

      return $this;
   }
}
