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
use Bootgly\ABI\Templates\Template;
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
   public static string|array|null $fallback = null;
   // ---
   /**
    * The Comparator instance to be used in the Assertion.
    */
   public static ?Comparator $Comparator;
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
      // self::$Comparator
      // $this->Snapshot

      // * Data
      // $actual
      // $expected
      // $With

      // * Metadata
      $this->asserted = false;
      $this->skipped = false;
   }
   public function __destruct ()
   {
      #self::$description = null;
      self::$fallback = null;
      // self::$Comparator
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
    * Compare the `actual` value with the `expected` value using the `With` Comparator (or the default Comparator).
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
         // $With fallback template (Assertion interface)
         // TODO: implement verbosity
         $fallback = $With->fail($actual, $expected);

         // @ Call fail in the Assertion
         $this->fail($fallback);
      }

      return $this;
   }

   public function fail (array $fallback): void
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
      // message
      $message = <<<MESSAGE

      \033[0;37;41m Fallback message: \033[0m
      
      MESSAGE;
      $message .= vsprintf(
         format: $fallback['format'],
         values: array_values($fallback['values'] ?? [])
      );
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
