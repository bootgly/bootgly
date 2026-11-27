<?php

use Bootgly\ACI\Events\Repeating;
use Bootgly\ACI\Observability\Metrics;
use Bootgly\ACI\Observability\Probes\Lag;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Lag probe measures each run against the due instant the loop hands it',
   test: function () {
      // ! A loop that only records the recurring timer
      $Loop = new class implements Repeating {
         public float $interval = 0.0;
         public null|Closure $Callback = null;
         public int $timers = 0;

         public function repeat (float $interval, Closure $Callback, float $slack = 0.0): int
         {
            $this->interval = $interval;
            $this->Callback = $Callback;
            return ++$this->timers;
         }
         public function schedule (Fiber $Fiber, mixed $value = null, int $flag = self::SCHEDULE_READ): bool
         {
            return false;
         }
         public function defer (float|int $deadline, Closure $Callback): int
         {
            return 0;
         }
         public function cancel (int $ID): bool
         {
            return false;
         }
         public function interrupt (Fiber $Fiber, Throwable $Throwable): bool
         {
            return false;
         }
      };
      // ! A clock the spec moves (nanoseconds)
      $Clock = new stdClass;
      $Clock->now = 1_000_000_000;
      $build = static function (Metrics $Metrics, Repeating $Loop, object $Clock): Lag {
         return new class ($Clock, $Metrics, $Loop) extends Lag {
            private object $Clock;

            public function __construct (object $Clock, Metrics $Metrics, Repeating $Loop)
            {
               $this->Clock = $Clock;
               parent::__construct($Metrics, $Loop);
            }
            protected function measure (): int
            {
               return $this->Clock->now;
            }
         };
      };
      $ms = 1_000_000;
      $t0 = $Clock->now;

      $Metrics = new Metrics;
      $Lag = $build($Metrics, $Loop, $Clock);
      $read = static fn (): array => $Metrics->read()['event_loop_lag_seconds']['series'][0];
      $run = static function (int $due, int $at) use ($Loop, $Clock): void {
         $Clock->now = $at;
         ($Loop->Callback)($due);
      };

      yield assert(
         assertion: $Loop->timers === 1 && $Loop->interval === Lag::INTERVAL
            && $Lag->interval === 0.1 && $Lag->timer === 1,
         description: 'building the probe arms one recurring timer at the default interval'
      );
      yield assert(
         assertion: $Metrics->read()['event_loop_lag_seconds']['type'] === 'histogram'
            && $read()['count'] === 0,
         description: 'the histogram is registered, empty'
      );

      // # On time: due t0+100, run t0+100
      $run($t0 + 100 * $ms, $t0 + 100 * $ms);
      yield assert(
         assertion: $read()['count'] === 1 && $read()['sum'] === 0.0,
         description: 'a run on its due instant observes zero'
      );

      // # A stall of 2.5 intervals: due t0+200, the loop reaches it at t0+450
      $run($t0 + 200 * $ms, $t0 + 450 * $ms);
      yield assert(
         assertion: $read()['count'] === 2 && abs($read()['sum'] - 0.25) < 1e-9,
         description: 'a run 2.5 intervals late observes 0.25 s'
      );

      // # Two stalls back to back: due t0+500 run t0+670, then the pass held past
      //   t0+600, so the loop runs that point at t0+670 too
      $run($t0 + 500 * $ms, $t0 + 670 * $ms);
      $run($t0 + 600 * $ms, $t0 + 670 * $ms);
      yield assert(
         assertion: $read()['count'] === 4 && abs($read()['sum'] - 0.49) < 1e-9,
         description: 'each of two back-to-back late runs is measured from its own due instant (0.17 s, 0.07 s)'
      );

      // # Two half-interval stalls in a row: due t0+700 run t0+760, due t0+800 run t0+860
      $run($t0 + 700 * $ms, $t0 + 760 * $ms);
      $run($t0 + 800 * $ms, $t0 + 860 * $ms);
      $series = $read();
      yield assert(
         assertion: $series['count'] === 6 && abs($series['sum'] - 0.61) < 1e-9
            && $series['buckets']['0.05'] === 1 && $series['buckets']['0.1'] === 4,
         description: 'both half-interval stalls are recorded (0.06 s each)'
      );

      // # A clock read before the due instant (a loop that breaks the contract)
      $run($t0 + 900 * $ms, $t0 + 899 * $ms);
      $series = $read();
      yield assert(
         assertion: $series['count'] === 7 && abs($series['sum'] - 0.61) < 1e-9
            && $series['buckets']['0.001'] === 2,
         description: 'a run before its due instant observes zero, never a negative delay'
      );

      // # Invalid intervals
      foreach (['zero' => 0.0, 'sub-millisecond' => 0.0001, 'NAN' => NAN, 'INF' => INF] as $label => $interval) {
         $threw = false;
         try {
            new Lag(new Metrics, $Loop, $interval);
         }
         catch (InvalidArgumentException) {
            $threw = true;
         }
         yield assert(
            assertion: $threw,
            description: "rejects a $label interval"
         );
      }
   }
);
