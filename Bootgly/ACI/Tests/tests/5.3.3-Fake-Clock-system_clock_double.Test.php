<?php

use Bootgly\ABI\Clock as SystemClock;
use Bootgly\ACI\Tests\Doubles\Doubling;
use Bootgly\ACI\Tests\Doubles\Fake\Clock;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Fake Clock — a Bootgly\ABI\Clock double: wall and monotonic readings move together',
   test: function () {
      $Clock = new Clock(100.5);

      // @ A Clock wherever one is expected
      $read = static fn (SystemClock $Clock): array => [$Clock->now, $Clock->time, $Clock->tick];
      yield assert(
         assertion: $Clock instanceof SystemClock && $Clock instanceof Doubling,
         description: 'the fake is a Bootgly\ABI\Clock and a resettable double'
      );
      yield assert(
         assertion: $read($Clock) === [100.5, 100, 0],
         description: 'starts frozen: wall at the given timestamp, monotonic at 0'
      );
      yield assert(
         assertion: $read($Clock) === [100.5, 100, 0],
         description: 'frozen: readings do not move on their own'
      );

      // @ advance(): seconds on the wall, nanoseconds on the tick
      $Clock->advance(1.25);
      yield assert(
         assertion: $read($Clock) === [101.75, 101, 1_250_000_000],
         description: 'advance() moves wall seconds and monotonic nanoseconds together'
      );
      $Clock->advance(3);
      yield assert(
         assertion: $read($Clock) === [104.75, 104, 4_250_000_000],
         description: 'advance() accumulates in both units'
      );
      $Clock->advance(1.005);
      yield assert(
         assertion: $Clock->tick === 5_255_000_000,
         description: 'advance() rounds to the nearest nanosecond (1.005 s is 1_005_000_000 ns)'
      );
      $Clock->advance(-1.005);

      // @ freeze(): a wall-clock step
      $Clock->freeze(42.9);
      yield assert(
         assertion: $read($Clock) === [42.9, 42, 5_255_000_000],
         description: 'freeze() sets the wall time; $time truncates it; the tick stays'
      );

      // @ A negative advance steps the wall back, never the monotonic time
      $Clock->advance(-2.9);
      yield assert(
         assertion: $read($Clock) === [40.0, 40, 5_255_000_000],
         description: 'advance() with negative seconds leaves the tick where it is'
      );

      // @ An advance past the tick's int range is refused, leaving the clock as it was
      $overflow = 0;
      foreach ([PHP_INT_MAX, 9.3e9, INF] as $seconds) {
         try {
            $Clock->advance($seconds);
         }
         catch (OverflowException) {
            $overflow++;
         }
      }
      yield assert(
         assertion: $overflow === 3 && $read($Clock) === [40.0, 40, 5_255_000_000],
         description: 'advance() past the int range of nanoseconds throws OverflowException and moves nothing'
      );

      // @ reset()
      yield assert(
         assertion: $Clock->reset() === $Clock,
         description: 'reset() returns the same fake'
      );
      yield assert(
         assertion: $read($Clock) === [100.5, 100, 0] && $Clock->initial === 100.5,
         description: 'reset() restores the initial timestamp and a zero tick'
      );

      // @ Readings are guarded from outside writes
      $refused = 0;
      foreach (['now' => 1.0, 'tick' => 1, 'time' => 1] as $property => $value) {
         try {
            $Clock->$property = $value;
         }
         catch (Error) {
            $refused++;
         }
      }
      yield assert(
         assertion: $refused === 3 && $read($Clock) === [100.5, 100, 0],
         description: 'now, tick and time cannot be written from outside'
      );
   }
);
