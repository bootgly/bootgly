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
use Closure;

use Bootgly\ACI\Tests\Assertion\Expectation;


trait Waiters
{
   use Expectation;
   use Callers;


   /**
    * Wait for a callable for a timeout.
    * 
    * @param Closure|int|float $expected The timeout to wait for the callable in microseconds or the output handler.
    *
    * @return self Returns the current instance for method chaining.
    */
   public function wait (Closure|float|int $expected): self
   {
      // ?
      if (isSet($this->arguments) === false) {
         throw new AssertionError('You need to use `->call` before set any waiter.');
      }

      // !
      // $arguments
      $arguments = $this->arguments;

      // @
      $Expectation = new Waiters\RunTimeout($expected, $arguments);

      $this->push($Expectation);

      return $this;
   }
}
