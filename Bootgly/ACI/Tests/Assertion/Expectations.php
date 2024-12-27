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
use Bootgly\ACI\Tests\Asserting\Modifier;
use Bootgly\ACI\Tests\Asserting;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
#use Bootgly\ACI\Tests\Assertion\Comparator;
use Bootgly\ACI\Tests\Assertion\Comparators;
use Bootgly\ACI\Tests\Assertion\Expectation;
use Bootgly\ACI\Tests\Assertion\Expectations\Behaviors;
use Bootgly\ACI\Tests\Assertion\Expectations\Callers;
use Bootgly\ACI\Tests\Assertion\Expectations\Delimiters;
use Bootgly\ACI\Tests\Assertion\Expectations\Finders;
use Bootgly\ACI\Tests\Assertion\Expectations\Matchers;
use Bootgly\ACI\Tests\Assertion\Expectations\Throwers;
use Bootgly\ACI\Tests\Assertion\Expectations\Waiters;


abstract class Expectations
{
   use Expectation;

   use Behaviors;
   use Callers;
   use Delimiters;
   use Finders;
   use Throwers;
   use Matchers;
   use Waiters;


   // * Config
   public self $to {
      get {
         $this->expecting = true;

         return $this;
      }
   }
   /**
    * Modifier that negates the expectation.
    * 
    * @example expect($value)->not->to->be(true)
    */ 
   public self $not {
      get {
         $this->expecting = true;

         $this->push(Modifier::Not);

         return $this;
      }
   }

   // * Data
   // ..$actual
   // ..$expected
   // ..$expectations

   // * Metadata
   // ..$expecting


   /**
    * Create a new Expectations instance.
    * The $comparator is required when using an $expected value.
    * The $expected value is required when using a $comparator.
    * 
    * @param mixed $actual The actual value to assert.
    * @param ?Op $comparator The comparator to use with $expected if provided.
    * @param mixed $expected The expected value to assert against if provided.
    */
   public function expect (
      mixed $actual,
      ?Op $comparator = null,
      mixed $expected = Argument::Undefined
   ): self
   {
      // ?
      if ($this->actual !== Argument::Undefined) {
         throw new AssertionError("The actual value is already set.");
      }

      // !
      $this->actual = $actual;

      if ($comparator !== null && $expected !== Argument::Undefined) {
         $this->expecting = true;
         $this->push(match ($comparator) {
            // Op
            Op::Equal => new Comparators\Equal($expected),
            Op::NotEqual => new Comparators\NotEqual($expected),
            Op::Identical => new Comparators\Identical($expected),
            Op::NotIdentical => new Comparators\NotIdentical($expected),
            Op::GreaterThan => new Comparators\GreaterThan($expected),
            Op::LessThan => new Comparators\LessThan($expected),
            Op::GreaterThanOrEqual => new Comparators\GreaterThanOrEqual($expected),
            Op::LessThanOrEqual => new Comparators\LessThanOrEqual($expected),
            default => throw new AssertionError('Invalid comparator.')
         });
      }
      else if ($comparator !== null && $expected === Argument::Undefined) {
         throw new AssertionError('The expected value must be defined when using a comparator.');
      }
      else if ($comparator === null && $expected !== Argument::Undefined) {
         throw new AssertionError('The comparator must be defined when using an expected value.');
      }

      return $this;
   }

   // # Data
   // be, call, delimit, find, match, throw, wait, ...
   // ..Behaviors (be)
   // ..Callers (call)
   // ..Delimiters (delimit)
   // ..Finders (find)
   // ..Matchers (match)
   // ..Throwers (throw)
   // ..Waiters (wait)

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
      if (is_iterable($this->actual) === false) {
         throw new AssertionError('The actual value must be an iterable.');
      }

      foreach ($this->actual as $key => $value) {
         $this->push($Iterator($value, $key));
      }

      return $this;
   }

   abstract public function assert (
      mixed $actual = Argument::Undefined,
      mixed $expected = Argument::Undefined,
      ?Asserting $using = null
   ): self;
}
