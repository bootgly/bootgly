<?php

use Bootgly\ABI\Clock;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Clock: wall readings in seconds, monotonic reading in nanoseconds, read live',
   test: function () {
      $Clock = new Clock;

      // @ Wall time: `$time` is time(), `$now` is microtime(true)
      $before = time();
      $time = $Clock->time;
      $after = time();
      yield assert(
         assertion: $time >= $before && $time <= $after,
         description: '$time reads the Unix time in whole seconds'
      );

      $before = microtime(true);
      $now = $Clock->now;
      $after = microtime(true);
      yield assert(
         assertion: is_float($now) && $now >= $before && $now <= $after,
         description: '$now reads the Unix time in float seconds'
      );
      yield assert(
         assertion: abs($Clock->now - $Clock->time) < 2.0,
         description: '$now and $time are the same clock in the same unit'
      );

      // @ Monotonic time: `$tick` is hrtime(true), in nanoseconds
      $before = hrtime(true);
      $tick = $Clock->tick;
      $after = hrtime(true);
      yield assert(
         assertion: is_int($tick) && $tick >= $before && $tick <= $after,
         description: '$tick reads the monotonic clock in integer nanoseconds'
      );

      $last = $Clock->tick;
      $decreasing = false;
      for ($index = 0; $index < 10_000; $index++) {
         $tick = $Clock->tick;
         if ($tick < $last) {
            $decreasing = true;
            break;
         }
         $last = $tick;
      }
      yield assert(
         assertion: $decreasing === false,
         description: '$tick never decreases'
      );

      // @ Every reading is live, not cached at construction
      $start = $Clock->tick;
      usleep(5_000);
      $elapsed = $Clock->tick - $start;
      yield assert(
         assertion: $elapsed >= 4_000_000 && $elapsed < 5_000_000_000,
         description: '$tick advances in nanoseconds (a 5 ms sleep reads as ~5,000,000)'
      );
   }
);
