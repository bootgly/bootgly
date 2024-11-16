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
   protected readonly Comparator $With;

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
   public function __destruct ()
   {
      self::$fallback = null;
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
      // ?
      if ($actual instanceof Comparator) {
         throw new AssertionError('The `actual` value cannot be an instance of Comparator!');
      }

      // @ Preset
      // ?! Snapshot: set $actual value if restored
      if (
         ($this->Snapshot ?? false)
         && ($this->Snapshot->restored ?? false)
         && isSet($this->actual)
      ) {
         $actual = $this->actual;
      }

      // @
      // # $With
      // !
      $With ??= self::$Comparator ?? new Comparators\Identical;
      // # $expected
      // ?!
      if (
         $expected instanceof Expectation
         && $With instanceof Snapshot
      ) {
         $expected->compare($actual, $expected);
      }
      else if ($expected instanceof Expectation) {
         $With = $expected;
      }

      $assertion = $With->compare($actual, $expected);

      // !
      // * Data
      $this->actual = $actual;
      $this->expected = $expected;
      $this->With = $With;
      // * Metadata
      $this->asserted = true;

      if ($assertion === false) {
         $this->fail();
      }

      return $this;
   }

   public function fail (): void
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
         'With:'
      ];
      Vars::debug(...[
         $this->actual,
         $this->expected,
         $this->With
      ]);
      Backtrace::$counter = $counter;
      // ---
      $backtrace = Vars::output(backtraces: [2]);
      $assertion = Vars::output(vars: true);
      $message = "\n";
      // + Custom fallback message
      if (self::$fallback) {
         $fallback = self::$fallback;
         $message = <<<MESSAGE

         \033[0;30;46m Fallback message: \033[0m $fallback

         MESSAGE;
      }
      self::$fallback = <<<MESSAGE
      Assertion failed in:
      $backtrace
      $message
      $assertion
      MESSAGE;
      // ---
      throw new AssertionError(self::$fallback);
   }
}
