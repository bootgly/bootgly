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

use Bootgly\ACI\Tests\Assertion\Expectation;


trait Waiters
{
   use Expectation;


   public function wait (int|float $timeout): self
   {
      // ?
      if (isSet($this->arguments) === false) {
         throw new AssertionError('You need to use `->call` before set any waiter.');
      }

      // !
      $arguments = $this->arguments;

      $this->set(
         new Waiters\RunTimeout($timeout, $arguments)
      );

      return $this;
   }
}
