<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Cases;


use AssertionError;

use Bootgly\ABI\Argument;
use Bootgly\ABI\Debugging\Backtrace;
use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Asserting;
use Bootgly\ACI\Tests\Asserting\Fallback;
use Bootgly\ACI\Tests\Asserting\Modifier;
use Bootgly\ACI\Tests\Asserting\Subassertion;
use Bootgly\ACI\Tests\Assertion\Comparators\Identical;
use Bootgly\ACI\Tests\Assertion\Expectation;
use Bootgly\ACI\Tests\Assertion\Expectations;
use Bootgly\ACI\Tests\Assertion\Snapshot;
use Bootgly\ACI\Tests\Assertion\Snapshots;


class Assertion extends Expectations
{
   use Snapshots;


   // * Config
   /**
    * The `description` of the Assertion.
    */
   public static ?string $description = null;
   /**
    * A custom `fallback` message displayed if the Assertion fails.
    */
   public static string|array|null $fallback = null;
   // ---
   // # Expectations
   // ..$to
   // # Snapshots
   // ..$Snapshot

   // * Data
   protected Asserting $using;
   // ---
   // # Expectations
   // ..$actual
   // ..$expected
   // ..$expectations
   // ..$expecting
   // ..$reset

   // * Metadata
   public private(set) bool $asserted;
   private bool $skipped;


   /**
    * Create a new Assertion instance.
    * 
    * @param string|null $description An optional `description` of the Assertion.
    * @param string|array|null $fallback A `fallback` message displayed if the Assertion fails.
    */
   public function __construct (
      string|null $description = null,
      string|array|null $fallback = null
   )
   {
      // * Config
      self::$description = $description;
      self::$fallback = $fallback;
      // ---
      // $this->Snapshot

      // * Data
      $this->actual = Argument::Undefined;
      $this->expected = Argument::Undefined;
      // $this->using

      // * Metadata
      $this->asserted = false;
      $this->skipped = false;
   }
   public function __clone(): void
   {
      // * Data
      $this->actual = Argument::Undefined;
      $this->expected = Argument::Undefined;
      // * Metadata
      $this->asserted = false;
      $this->skipped = false;

      $this->reset();
   }
   public function __destruct ()
   {
      // * Config
      #self::$description = null;
      self::$fallback = null;
      // ---
      // $this->Snapshot
   }
   public function __get (string $name): mixed
   {
      return match ($name) {
         // * Metadata
         'asserted' => $this->asserted,
         'skipped' => $this->skipped,
         default => null
      };
   }

   /**
    * Skip the Assertion.
    *
    * @return self Returns the current instance for method chaining.
    */
   public function skip (): self
   {
      $this->skipped = true;

      return $this;
   }

   // # ExA (expect, *, assert) API
   // ## Expectation API
   // ..Expectations
   // ## Assert API
   /**
    * Compare the `actual` value with the `expected` value using the `using` Comparator (or the default Comparator).
    * 
    * @param mixed $actual The `actual` value provided as input for the Assertion.
    * @param mixed $expected The `expected` value to be compared with the `actual` value in the assertion.
    * @param ?Asserting $using The Assertion interface to be used in the Assertion.
    * 
    * @return self Returns the current instance for method chaining.
    */
   public function assert (
      mixed $actual = Argument::Undefined,
      mixed $expected = Argument::Undefined,
      ?Asserting $using = null,
   ): self
   {
      // ?
      if ($this->asserted) {
         throw new AssertionError('The `assert` method has already been called!');
      }

      // @ 1️⃣ Define
      $expectations = $this->expectations;
      // # Metadata
      $this->asserted = true;
      // # Data
      // $actual
      $actual = $this->actual === Argument::Undefined
         ? $actual
         : $this->actual;
      // ? Check if the `actual` value is undefined
      if ($actual === Argument::Undefined) {
         throw new AssertionError('The `actual` value must be defined!');
      }
      // ? Check if the `actual` value is an instance of Comparator
      if ($actual instanceof Asserting) {
         throw new AssertionError('The `actual` value cannot be an instance of Asserting!');
      }
      // $expected
      // ? Check if the `expected` value is defined when using Expectations
      if ($expected !== Argument::Undefined && $expectations) {
         throw new AssertionError('The `expected` value cannot be defined when using Expectations!');
      }
      $expected = $this->expected === Argument::Undefined
         ? $expected
         : $this->expected;
      // $using
      $using ??= new Identical;
      if (
         $expected instanceof Asserting
         && !$using instanceof Snapshot
      ) {
         $using = $expected;
      }

      // @ 2️⃣ Reset
      // ?! Snapshot: reset $actual value if Snapshot was restored
      if (
         ($this->Snapshot ?? false)
         && ($this->Snapshot->restored ?? false)
         && isSet($this->actual)
      ) {
         $actual = $this->actual;
      }

      // @ 3️⃣ Assert
      // !
      // * Data
      $this->actual = $actual;
      $this->expected = $expected;
      $this->using = $using;
      // * Metadata
      // expectations
      // ?! Expectations
      if ($expectations === null) {
         $this->to->be(
            $using instanceof Asserting
            && !$using instanceof Snapshot
               ? $using
               : $expected
         );
      }

      // # Assertion
      $results = [];
      // ---
      // # Modifier
      // value
      $false = false; // Not modifier
      // logical
      $and = null;
      $or = null;
      // @
      foreach ($expectations as $index => $Expectation) {
         // # Modifier
         $next = $expectations[$index + 1] ?? null;
         // Not
         if ($Expectation === Modifier::Not) {
            $false = true;
         }
         // And
         if ($next === Modifier::And) {
            $and = ($and === null && $or === null)
               ? true
               : throw new AssertionError('The `and` modifier can only be used in isolation (without the `or`) and cannot be used more than once.');
         }
         // Or
         else if ($next === Modifier::Or) {
            $or = ($or === null && $and === null)
               ? true
               : throw new AssertionError('The `or` modifier can only be used in isolation (without the `and`) and cannot be used more than once.');
         }

         if ($Expectation instanceof Modifier) {
            continue;
         }

         // ---

         if ($using instanceof Snapshot) {
            $using->assert($actual, $expected);
         }

         // # Assertion
         /**
          * @var Asserting $Expectation
          */
         $results[$index] = $Expectation->assert($actual, $expected);

         // # Subassertion
         if (
            $Expectation instanceof Subassertion
            && $Expectation->subassertion !== null
         ) {
            $Subassertion = clone $this;
            $Subassertion->expect($Expectation->actual);

            $Expectation->subassertion = $Expectation->subassertion->bindTo(
               $Subassertion
            );
            $Expectation->output();
 
            $Subassertion->assert();
         }

         // # Result
         $failed = $results[$index] === $false;

         if ($failed && $or === true) {
            $or = false;

            continue;
         }
         else if ($failed && $and === true) {
            $and = false;
         }

         // @ Fail
         if ($failed) {
            // * Data
            $using = $Expectation;
            // ---
            $this->expected = $Expectation;
            $this->using = $using;

            // TODO: implement verbosity
            /**
             * @var Asserting $using
             */
            $Fallback = $using->fail($actual, $expected);
   
            // @ Call fail in the Assertion
            $this->fail($Fallback);
         }
      }

      return $this;
   }

   public function fail (Fallback $Fallback): void
   {
      $counter = Backtrace::$counter;
      Backtrace::$counter = false;
      Vars::$exit = false;
      Vars::$debug = true;
      Vars::$print = false;
      Vars::$traces = 3;
      Vars::$labels = [
         'actual:',
         'expected:',
         'using:'
      ];
      Vars::debug(...[
         $this->actual,
         $this->expected,
         $this->using
      ]);
      Backtrace::$counter = $counter;
      // ---
      $backtrace = Vars::output(backtraces: [2]);
      $assertion = Vars::output(vars: true);
      // message
      $message = <<<MESSAGE

      \033[0;30;41m Fallback message: \033[0m
      
      MESSAGE;
      $message .= (string) $Fallback;
      $message .= "\033[0m\n";
      // additional
      $additional = "\033[F\033[F"; // move the cursor up 2 lines
      // + Fallback additional message
      if (self::$fallback) {
         $additional = <<<MESSAGE
         \033[0;30;46m Fallback additional message: \033[0m

         MESSAGE;

         if (is_array(self::$fallback)) {
            foreach (self::$fallback as $key => $value) {
               $additional = $key;
            }
         }

         $additional .= new Template(<<<'MESSAGE'
         @> $fallback;
         MESSAGE)->render(
            ['fallback' => self::$fallback]
         ) ?? '';
      }

      self::$fallback = <<<MESSAGE
      Assertion failed in:
      $backtrace
      $message
      $assertion
      $additional
      MESSAGE;

      // ---
      throw new AssertionError(self::$fallback);
   }
}
