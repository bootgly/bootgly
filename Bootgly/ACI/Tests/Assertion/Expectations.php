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


use AssertionError;
use Closure;

use Bootgly\ABI\Argument;
use Bootgly\ACI\Tests\Asserting;
use Bootgly\ACI\Tests\Assertion\Expectation;
use Bootgly\ACI\Tests\Assertion\Expectations\Comparators;
use Bootgly\ACI\Tests\Assertion\Expectations\Delimiters;
use Bootgly\ACI\Tests\Assertion\Expectations\Finders;
use Bootgly\ACI\Tests\Assertion\Expectations\Matchers;
use Bootgly\ACI\Tests\Assertion\Expectations\Throwers;
use Bootgly\ACI\Tests\Assertion\Expectations\Validators;


abstract class Expectations
{
   use Expectation;

   use Comparators;
   use Delimiters;
   use Finders;
   use Throwers;
   use Matchers;
   use Validators;


   // * Config
   public self $to {
      get {
         $this->expecting = true;

         return $this;
      }
   }

   // * Data
   // # Assertion
   protected mixed $actual;
   protected mixed $expected;

   // * Metadata
   // ..Expectation


   public function expect (mixed $actual): self
   {
      $this->actual = $actual;

      return $this;
   }

   // # Data
   // be (generic), compare, delimit, find, match, throw, validate, ...
   public function be (mixed $expected): self
   {
      $this->expected = $expected;

      $this->expectation = $expected instanceof Asserting
         ? $expected
         : new Comparators\Identical($expected);

      return $this;
   }
   // ..Comparators
   // ..Delimiters
   // ..Finders
   // ..Matchers
   // ..Throwers
   // ..Validators

   // # Dataset
   /**
    * Iterate over $actual values.
    *
    * "expect that $actual iterate over $actual..."
    *
    * The $actual must be an iterable.
    *
    * @param Closure $Iterator The iterator function.
    *
    * @return self Returns the current instance for method chaining.
    */   
   public function iterate (Closure $Iterator): self
   {
      if (!is_iterable($this->actual)) {
         throw new AssertionError('The actual value must be an iterable.');
      }

      foreach ($this->actual as $key => $value) {
         $this->expectation = $Iterator($value, $key);
      }

      return $this;
   }

   abstract public function assert (
      mixed $actual = Argument::Undefined,
      mixed $expected = Argument::Undefined,
      ?Asserting $using = null
   ): self;
}
