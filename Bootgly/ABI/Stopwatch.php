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


/**
 * Measures elapsed time on a Clock's monotonic reading (`$tick`).
 *
 * Works like a hand stopwatch: `start()` runs it, `stop()` pauses it keeping
 * what it measured, a new `start()` resumes from there, and `reset()` zeroes
 * it. `$elapsed` can be read at any moment — while running it includes the
 * current run. A wall-clock change never stretches or shrinks a measurement.
 */
class Stopwatch
{
   // * Config
   /**
    * The clock the stopwatch reads; a system Clock unless one is given.
    */
   public private(set) Clock $Clock;

   // * Metadata
   /**
    * The monotonic tick the current run started at; null while stopped.
    */
   public private(set) null|int $started = null;
   /**
    * Nanoseconds measured by the runs already stopped.
    */
   private int $measured = 0;
   /**
    * Whether the stopwatch is running.
    */
   public bool $running {
      get => $this->started !== null;
   }
   /**
    * Elapsed time in nanoseconds: every stopped run plus the current one.
    */
   public int $elapsed {
      get => $this->started === null
         ? $this->measured
         : $this->measured + ($this->Clock->tick - $this->started);
   }
   /**
    * Elapsed time in seconds (`$elapsed / 1e9`).
    */
   public float $seconds {
      get => $this->elapsed / 1_000_000_000;
   }


   /**
    * Create a stopped stopwatch.
    *
    * @param null|Clock $Clock The clock to read; a system Clock by default.
    */
   public function __construct (null|Clock $Clock = null)
   {
      // * Config
      $this->Clock = $Clock ?? new Clock;
   }

   /**
    * Start the stopwatch, or resume it after a stop. Starting a running
    * stopwatch changes nothing.
    */
   public function start (): static
   {
      // ?
      if ($this->started !== null) {
         return $this;
      }

      // @
      $this->started = $this->Clock->tick;

      // :
      return $this;
   }

   /**
    * Stop the stopwatch, keeping what it measured. Stopping a stopped
    * stopwatch changes nothing.
    */
   public function stop (): static
   {
      // ?
      if ($this->started === null) {
         return $this;
      }

      // @
      $this->measured += $this->Clock->tick - $this->started;
      $this->started = null;

      // :
      return $this;
   }

   /**
    * Stop the stopwatch and zero what it measured.
    */
   public function reset (): static
   {
      // @
      $this->measured = 0;
      $this->started = null;

      // :
      return $this;
   }
}
