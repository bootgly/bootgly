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
use Error;
use Exception;
use Throwable;

use Bootgly\ACI\Tests\Assertion\Expectation;


/**
 * @property mixed $expectation
 */
trait Throwers
{
   use Expectation;
   use Callers;


   /**
    * Throw an exception.
    * "expect that $actual throw $expected".
    * The $actual must be a callable.
    *
    * @param Throwable $expected The Throwable to check if the $actual throws.
    *
    * @return self Returns the current instance for method chaining.
    */
   public function throw (Throwable $expected): self
   {
      // ?
      if (isSet($this->arguments) === false) {
         throw new AssertionError('You need to use `->call` before set any thrower.');
      }

      // !
      $arguments = $this->arguments;

      $Expectation = match (true) {
         $expected instanceof Error =>
            new Throwers\ThrowError($expected, $arguments),
         $expected instanceof Exception => 
            new Throwers\ThrowException($expected, $arguments),
         default => new Throwers\ThrowThrowable($expected, $arguments)
      };
      $this->push($Expectation);

      return $this;
   }
}
