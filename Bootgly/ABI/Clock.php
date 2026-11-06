<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI;


use function hrtime;
use function microtime;
use function time;
use Closure;


/**
 * The system clock: wall time and monotonic time behind one injectable object.
 *
 * Each reading asks the system when it is read — nothing is cached. Code that
 * needs time takes a Clock instead of calling `time()`, `microtime()` or
 * `hrtime()` itself, so a test can hand it a double that is frozen or moved
 * by hand (`Bootgly\ACI\Tests\Doubles\Fake\Clock`).
 *
 * Pick the reading by what it measures:
 * - `$now` and `$time` follow the wall clock — the date the system shows. Use
 *   them for timestamps, expirations and anything compared with stored dates;
 *   they jump when the system clock is set.
 * - `$tick` follows the monotonic clock — it never goes back and never jumps.
 *   Use it for deadlines, timeouts and durations (see `Bootgly\ABI\Stopwatch`); its
 *   origin is arbitrary, so only the difference between two ticks means
 *   anything.
 */
class Clock
{
   // * Metadata
   /**
    * Wall-clock time, in seconds since the Unix epoch, with microseconds
    * (`microtime(true)`).
    */
   public float $now {
      get => microtime(true);
   }
   /**
    * Wall-clock time, in whole seconds since the Unix epoch (`time()`).
    */
   public int $time {
      get => time();
   }
   /**
    * Monotonic time, in nanoseconds from an arbitrary origin (`hrtime(true)`).
    */
   public int $tick {
      get => (int) hrtime(true);
   }


   /**
    * Read a legacy `clock` closure as a Clock.
    *
    * Before 1.1.0, the `clock` options (Cache, Queues, RateLimit) took a
    * closure returning a Unix timestamp; such a closure is read here, so every
    * reading still goes through a Clock: `$time` is `(int) $Closure()` —
    * exactly what the options computed before —, `$now` the same value as a
    * float, and `$tick` that value in nanoseconds (it only moves when the
    * closure's value does).
    *
    * @deprecated 1.1.0 Pass a `Bootgly\ABI\Clock` (or the test double
    *    `Bootgly\ACI\Tests\Doubles\Fake\Clock`) instead of a closure. The
    *    closure form, and this method, are removed in 2.0.0.
    *
    * @param Closure $Closure Returns the current Unix timestamp (int or float seconds).
    *
    * @return Clock A Clock whose readings come from the closure.
    */
   public static function adapt (Closure $Closure): Clock
   {
      // :
      return new class ($Closure) extends Clock {
         // * Data
         private Closure $Closure;

         // * Metadata
         public float $now {
            get => (float) ($this->Closure)();
         }
         public int $time {
            get => (int) ($this->Closure)();
         }
         public int $tick {
            get => (int) ((float) ($this->Closure)() * 1_000_000_000);
         }


         public function __construct (Closure $Closure)
         {
            // * Data
            $this->Closure = $Closure;
         }
      };
   }
}
