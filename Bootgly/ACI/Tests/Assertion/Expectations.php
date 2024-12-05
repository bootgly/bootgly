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
use DateTime;
use Throwable;

use Bootgly\ABI\Argument;
use Bootgly\ACI\Tests\Asserting;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Interval;
use Bootgly\ACI\Tests\Assertion\Expectations\Comparators;
use Bootgly\ACI\Tests\Assertion\Expectations\Delimiters;
use Bootgly\ACI\Tests\Assertion\Expectations\Matchers;


abstract class Expectations
{
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
   protected bool $expecting = false;
   protected mixed $expectation {
      get { // last expectation in the stack
         return $this->expectations[count($this->expectations) - 1];
      }
      set (mixed $expectation) {
         // ?
         $notExpecting = $this->expecting === false;
         $isAsserting = $expectation instanceof Asserting;
         if ($notExpecting && $isAsserting) {
            throw new AssertionError('You need to use `->to` before set any expectation.');
         }

         $this->expectations ??= [];
         $this->expectations[] = $expectation;

         $this->expecting = false;
      }
   }
   protected ?array $expectations = null;


   public function expect (mixed $actual): self
   {
      $this->actual = $actual;

      return $this;
   }

   // # Data
   // be, contain, delimit, find, have, match, throw, validate, ...
   public function be (mixed $expected): self
   {
      $this->expected = $expected;

      $this->expectation = $expected instanceof Asserting
         ? $expected
         : new Comparators\Identical($expected);

      return $this;
   }
   public function delimit (
      int|float|DateTime $from,
      int|float|DateTime $to,
      Interval $interval = Interval::Closed
   ): self
   {
      $this->expectation = match ($interval) {
         Interval::Open =>
            new Delimiters\OpenInterval($from, $to),
         Interval::Closed =>
            new Delimiters\ClosedInterval($from, $to),
         Interval::LeftOpen =>
            new Delimiters\LeftOpenInterval($from, $to),
         Interval::RightOpen =>
            new Delimiters\RightOpenInterval($from, $to),
         default =>
            throw new AssertionError('Invalid interval delimiter.')
      };

      return $this;
   }
   public function have (mixed $resource, mixed $value = Argument::Undefined): self
   {
      $this->expectation = $resource;

      return $this;
   }
   public function match (string $pattern): self
   {
      $this->expectation = new Matchers\Regex($pattern);

      return $this;
   }

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

   // # Callables
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

      $this->expectation = $expected;

      return $this;
   }

   abstract public function assert (
      mixed $actual = Argument::Undefined,
      mixed $expected = Argument::Undefined,
      Asserting $using = new Comparators\Identical
   ): self;
}
