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

use Bootgly\ABI\Debugging\Backtrace;
use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Assertion\Comparator;
use Bootgly\ACI\Tests\Assertion\Comparators;
use Bootgly\ACI\Tests\Assertion\Expectation;
use Bootgly\ACI\Tests\Assertion\Snapshot;
use Bootgly\ACI\Tests\Assertion\Snapshots;


class Assertion
{
   // * Config
   /**
    * The `description` of the Assertion.
    */
   public static ?string $description = null;
   /**
    * A custom `fallback` message displayed if the Assertion fails.
    */
   public static ?string $fallback = null;
   // ---
   /**
    * The Comparator instance to be used in the Assertion.
    */
   public static Comparator $Comparator;
   /**
    * The Snapshot instance to be used in the Assertion.
    */
   public Snapshot $Snapshot {
      get => $this->Snapshot ??= new Snapshots\InMemoryDefault;
   }

   // * Data
   protected mixed $actual;
   protected mixed $expected;

   // * Metadata
   private bool $asserted;
   private bool $skipped;


   /**
    * Create a new Assertion instance.
    * 
    * @param string|null $description An optional `description` of the Assertion.
    * @param string|null $fallback A custom `fallback` message displayed if the Assertion fails.
    */
   public function __construct (
      string|null $description = null,
      string|null $fallback = null
   )
   {
      // * Config
      self::$description = $description;
      self::$fallback = $fallback;
      // ---
      // ...

      // * Data
      // ...

      // * Metadata
      $this->asserted = false;
      $this->skipped = false;
   }
   public function __get (string $name): mixed
   {
      switch ($name) {
         case 'asserted':
            return $this->asserted;
         case 'skipped':
            return $this->skipped;
         default:
            return null;
      }
   }

   public function __destruct ()
   {
      // * Config
      self::$description = null;
      self::$fallback = null;
   }

   // # Snapshot
   /**
    * Capture a snapshot of the current actual value.
    * 
    * @param string $snapshot The snapshot name.
    * 
    * @return self Returns the current instance for method chaining.
    */
   public function capture (string $snapshot): self
   {
      $this->Snapshot ??= new Snapshots\InMemoryDefault;

      $this->Snapshot->capture($snapshot, $this->actual);

      return $this;
   }
 
   /**
    * Restore a snapshot value into the current actual value.
    * 
    * @param string $snapshot The snapshot name.
    * 
    * @return self Returns the current instance for method chaining.
    */
   public function restore (string $snapshot): self
   {
      $this->Snapshot ??= new Snapshots\InMemoryDefault;

      $this->Snapshot->restore($snapshot, $this->actual);

      return $this;
   }

   /**
    * Capture and restore a snapshot value into the current actual value.
    *
    * @param string $name The snapshot name.
    *
    * @return self Returns the current instance for method chaining.
    */
   public function snapshot (string $name): self
   {
      $this->Snapshot->capture($name, $this->actual);

      $this->Snapshot->restore($name, $this->actual);

      return $this;
   }

   // .
   /**
    * Assert the Assertion.
    * 
    * @param mixed $actual The `actual` value provided as input for the Assertion.
    * @param mixed $expected The `expected` value to be compared with the `actual` value in the assertion.
    * @param ?Comparator $With The Comparator instance to be used in the Assertion.
    * 
    * @return self Returns the current instance for method chaining.
    */
   public function assert (
      mixed $actual,
      mixed $expected,
      ?Comparator $With = null,
   ): self
   {
      // ?! Snapshot: set $actual value if restored
      if (
         ($this->Snapshot ?? false)
         && ($this->Snapshot->restored ?? false)
         && isSet($this->actual)
      ) {
         $actual = $this->actual;
      }

      // * Data
      $this->actual = $actual;
      $this->expected = $expected;
      // * Metadata
      $this->asserted = true;

      // @ Handle
      // # $actual
      // ?
      if ($actual instanceof Comparator) {
         throw new AssertionError('The `actual` value cannot be an instance of Comparator!');
      }
      // # $With
      // !
      $With ??= self::$Comparator ?? new Comparators\Identical;
      // # $expected
      // ?!
      if (
         $this->expected instanceof Expectation
         && $With instanceof Snapshot
      ) {
         $this->expected->compare($actual, $expected);
      }
      else if ($this->expected instanceof Expectation) {
         $With = $this->expected;
      }

      // @:
      $assertion = $With->compare(
         $actual,
         $expected
      );

      if ($assertion === false) {
         $this->fail();

         throw new AssertionError(self::$fallback);
      }

      return $this;
   }

   private function fail (): void
   {
      $counter = Backtrace::$counter;
      Backtrace::$counter = false;
      Vars::$exit = false;
      Vars::$debug = true;
      Vars::$print = false;
      Vars::$traces = 3;

      Vars::$labels = [
         'Actual:',
         'Expected:'
      ];
      Vars::debug(...[
         &$this->actual,
         &$this->expected
      ]);
      Backtrace::$counter = $counter;

      $backtrace = Vars::output(backtraces: [2]);
      $vars = Vars::output(vars: true);

      // Default fallback message
      $fallback_default = <<<MESSAGE
      Assertion failed in:
      $backtrace
      
      $vars
      MESSAGE;

      // + Custom fallback message
      if (self::$fallback) {
         $fallback_custom = self::$fallback;

         $fallback_default .= <<<MESSAGE
         \033[0;30;46m Fallback message: \033[0m $fallback_custom


         MESSAGE;

         self::$fallback = $fallback_default;
      }
   }
}
