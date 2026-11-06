<?php

use Bootgly\ABI\Clock;
use Bootgly\ABI\Stopwatch;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Stopwatch: the system Clock by default, measuring real monotonic time',
   test: function () {
      $Stopwatch = new Stopwatch;

      yield assert(
         assertion: $Stopwatch->Clock::class === Clock::class,
         description: 'without a Clock, the stopwatch reads the system Clock'
      );

      $Stopwatch->start();
      usleep(10_000);
      $running = $Stopwatch->elapsed;
      $Stopwatch->stop();
      $stopped = $Stopwatch->elapsed;
      usleep(5_000);

      yield assert(
         assertion: $running >= 9_000_000 && $running < 5_000_000_000,
         description: 'a 10 ms sleep reads as ~10,000,000 ns'
      );
      yield assert(
         assertion: $stopped >= $running && $Stopwatch->elapsed === $stopped,
         description: 'a stopped stopwatch no longer moves'
      );
      yield assert(
         assertion: abs($Stopwatch->seconds - ($stopped / 1_000_000_000)) < 1e-12,
         description: 'seconds is elapsed divided by 1e9'
      );
   }
);
