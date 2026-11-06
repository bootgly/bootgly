<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Doubles\Fake;


use const PHP_INT_MAX;
use function round;
use OverflowException;

use Bootgly\ABI\Clock as SystemClock;
use Bootgly\ACI\Tests\Doubles\Doubling;


/**
 * Deterministic clock fake for time-sensitive tests.
 *
 * A `Bootgly\ABI\Clock` whose readings only move by hand, so it can be handed
 * to anything that takes a Clock. It extends the Clock itself (PHP has single
 * inheritance), so it implements the `Doubling` contract directly instead of
 * extending the `Fake` base, which adds nothing but a default `reset()`.
 *
 * Its wall time (`$now`, and `$time` truncated from it) starts at the given
 * timestamp; its monotonic time (`$tick`) starts at 0. `advance()` moves both
 * by the same amount — seconds on the wall, nanoseconds on the tick —,
 * `freeze()` sets the wall time alone (a wall-clock step, which monotonic time
 * never follows) and `reset()` restores both.
 */
final class Clock extends SystemClock implements Doubling
{
   // * Config
   /**
    * Initial timestamp restored by reset().
    */
   public private(set) float $initial;

   // * Data
   /**
    * Current fake wall-clock timestamp, in seconds.
    */
   public private(set) float $now {
      get => $this->now;
   }
   /**
    * Current fake monotonic time, in nanoseconds.
    */
   public private(set) int $tick = 0 {
      get => $this->tick;
   }

   // * Metadata
   /**
    * Current fake wall-clock timestamp, in whole seconds (`(int) $now`).
    */
   public int $time {
      get => (int) $this->now;
   }


   /**
    * Create a deterministic clock at the given timestamp.
    */
   public function __construct (int|float $at = 0)
   {
      // * Config
      $this->initial = (float) $at;

      // * Data
      $this->now = $this->initial;
   }

   /**
    * Move the fake clock forward by the given seconds: the wall time by them
    * and the monotonic time by them in nanoseconds. A negative amount moves
    * the wall time back and leaves the monotonic time where it is.
    *
    * @throws OverflowException When the monotonic time would pass the int
    *    range (about 292 years of nanoseconds); the clock is left unchanged.
    */
   public function advance (int|float $seconds): void
   {
      // ? Monotonic time never goes back
      $nanoseconds = 0;
      if ($seconds > 0) {
         // ! Rounded to the nearest nanosecond: 1.005 s is 1_005_000_000 ns,
         //   not the 1_004_999_999 its float product truncates to
         $product = round($seconds * 1_000_000_000);
         // ? The tick is an int of nanoseconds: refuse a move past its range
         //   instead of letting the cast wrap or the sum turn into a float
         if ($product >= (float) (PHP_INT_MAX - $this->tick)) {
            throw new OverflowException(
               "The fake clock cannot advance {$seconds} s: its monotonic time would pass the int range of nanoseconds."
            );
         }
         $nanoseconds = (int) $product;
      }

      // @
      $this->now += (float) $seconds;
      $this->tick += $nanoseconds;
   }

   /**
    * Set the fake wall clock to an exact timestamp. The monotonic time does
    * not move: like a system clock that is set, it is a wall-clock step.
    */
   public function freeze (int|float $at): void
   {
      $this->now = (float) $at;
   }

   /**
    * Reset the fake clock to its initial timestamp and its monotonic time to 0.
    */
   public function reset (): static
   {
      $this->now = $this->initial;
      $this->tick = 0;

      return $this;
   }
}
