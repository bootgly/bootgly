<?php

use Bootgly\ABI\Stopwatch;
use Bootgly\ACI\Tests\Doubles\Fake\Clock;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Stopwatch: start/stop/reset and elapsed time on a frozen, hand-advanced clock',
   test: function () {
      $Clock = new Clock(1_000);
      $Stopwatch = new Stopwatch($Clock);

      yield assert(
         assertion: $Stopwatch->Clock === $Clock,
         description: 'the given Clock is the one read'
      );
      yield assert(
         assertion: $Stopwatch->running === false
            && $Stopwatch->started === null
            && $Stopwatch->elapsed === 0,
         description: 'a new stopwatch is stopped at zero'
      );

      $Clock->advance(3);
      yield assert(
         assertion: $Stopwatch->elapsed === 0,
         description: 'time passing before start() is not measured'
      );

      // @ Run
      yield assert(
         assertion: $Stopwatch->start() === $Stopwatch,
         description: 'start() returns the stopwatch'
      );
      yield assert(
         assertion: $Stopwatch->running === true && $Stopwatch->started === 3_000_000_000,
         description: 'start() records the monotonic tick it started at'
      );

      $Clock->advance(1.5);
      yield assert(
         assertion: $Stopwatch->elapsed === 1_500_000_000,
         description: 'elapsed reads nanoseconds while running'
      );
      yield assert(
         assertion: $Stopwatch->seconds === 1.5,
         description: 'seconds reads the same time as float seconds'
      );

      $Stopwatch->start();
      $Clock->advance(0.5);
      yield assert(
         assertion: $Stopwatch->elapsed === 2_000_000_000,
         description: 'start() on a running stopwatch does not restart it'
      );

      // @ Wall-clock step
      $Clock->freeze(10);
      yield assert(
         assertion: $Stopwatch->elapsed === 2_000_000_000,
         description: 'a wall-clock step does not change the measurement'
      );

      // @ Stop
      yield assert(
         assertion: $Stopwatch->stop() === $Stopwatch,
         description: 'stop() returns the stopwatch'
      );
      $Clock->advance(10);
      yield assert(
         assertion: $Stopwatch->running === false && $Stopwatch->elapsed === 2_000_000_000,
         description: 'a stopped stopwatch keeps what it measured'
      );

      $Stopwatch->stop();
      yield assert(
         assertion: $Stopwatch->elapsed === 2_000_000_000,
         description: 'stop() on a stopped stopwatch changes nothing'
      );

      // @ Resume
      $Stopwatch->start();
      $Clock->advance(0.25);
      yield assert(
         assertion: $Stopwatch->elapsed === 2_250_000_000,
         description: 'start() after stop() resumes from the measured time'
      );
      $Stopwatch->stop();
      yield assert(
         assertion: $Stopwatch->elapsed === 2_250_000_000 && $Stopwatch->seconds === 2.25,
         description: 'every run adds up once stopped'
      );

      // @ Reset
      $Stopwatch->start();
      $Clock->advance(1);
      yield assert(
         assertion: $Stopwatch->reset() === $Stopwatch,
         description: 'reset() returns the stopwatch'
      );
      yield assert(
         assertion: $Stopwatch->running === false
            && $Stopwatch->started === null
            && $Stopwatch->elapsed === 0
            && $Stopwatch->seconds === 0.0,
         description: 'reset() stops the stopwatch and zeroes it'
      );

      $Clock->advance(1);
      $Stopwatch->start();
      $Clock->advance(0.125);
      yield assert(
         assertion: $Stopwatch->elapsed === 125_000_000,
         description: 'a reset stopwatch measures from its next start()'
      );
   }
);
