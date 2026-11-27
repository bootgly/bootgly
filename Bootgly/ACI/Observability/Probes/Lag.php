<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Observability\Probes;


use function hrtime;
use function is_finite;
use InvalidArgumentException;

use Bootgly\ACI\Events\Repeating;
use Bootgly\ACI\Observability\Metrics;
use Bootgly\ACI\Observability\Metrics\Histogram;


/**
 * Event-loop lag: how late a recurring timer of the loop runs.
 *
 * Building the probe arms one recurring timer on the loop and registers the
 * `event_loop_lag_seconds` histogram. Each run observes `now - due`, where
 * `due` is the grid point the loop hands the run (the `Repeating` contract):
 * work that holds the loop over a due point — a blocking call, a long
 * callback, a pass that runs late — shows up as the delay of that run.
 *
 * Sampling: a stall of `S` seconds records at least `S - interval`; a stall
 * shorter than the interval is recorded only when it overlaps a due point.
 * The series is additive — summed across workers, the histogram is the
 * fleet's distribution.
 */
class Lag
{
   /** Default seconds between two runs: a stall of S seconds records at least S - 0.1. */
   public const float INTERVAL = 0.1;
   /** @var list<float> Default upper bounds (le), in seconds. */
   public const array BUCKETS = [0.001, 0.0025, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0];

   // * Config
   /** Seconds between two runs. */
   public private(set) float $interval;

   // * Metadata
   /** The probe's recurring timer on the loop, for `cancel()`. */
   public private(set) int $timer;
   private Histogram $Histogram;


   /**
    * Register the lag histogram and arm its recurring timer on the loop.
    *
    * @param Metrics $Metrics The registry the histogram is pushed onto.
    * @param Repeating $Loop The loop whose lag is measured.
    * @param float $interval Seconds between two runs; at least one millisecond.
    * @param list<float> $buckets Histogram upper bounds, in seconds.
    * @param array<string,string> $labels Labels of the series.
    *
    * @throws InvalidArgumentException When the interval is not a finite number of at least
    *         one millisecond, or the buckets are invalid (see `Histogram`).
    */
   public function __construct (
      Metrics $Metrics,
      Repeating $Loop,
      float $interval = self::INTERVAL,
      array $buckets = self::BUCKETS,
      array $labels = []
   )
   {
      // ? A grid finer than the loop's resolution would not be the loop's grid
      if (is_finite($interval) === false || $interval < 0.001) {
         throw new InvalidArgumentException(
            'Lag requires a finite interval of at least 0.001 seconds.'
         );
      }

      // * Config
      $this->interval = $interval;

      // * Metadata
      $this->Histogram = new Histogram(
         name: 'event_loop_lag_seconds',
         help: 'How late a recurring timer of the event loop ran, in seconds.',
         labels: $labels,
         buckets: $buckets
      );

      // @
      $Metrics->push($this->Histogram);
      $this->timer = $Loop->repeat($interval, $this->observe(...));
   }

   /**
    * Read the monotonic clock, in nanoseconds.
    */
   protected function measure (): int
   {
      // :
      return (int) hrtime(true);
   }

   /**
    * Record one run's lag: how late after its due instant the loop ran it.
    *
    * @param int $due The monotonic nanosecond instant the run was due at.
    */
   private function observe (int $due): void
   {
      $lag = $this->measure() - $due;

      // @ A clock read before the due point (never, on a loop that keeps
      //   the contract) counts as on time, not as a negative delay
      $this->Histogram->observe($lag > 0 ? $lag / 1_000_000_000 : 0.0);
   }
}
