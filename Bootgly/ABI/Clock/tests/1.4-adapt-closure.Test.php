<?php

use Bootgly\ABI\Clock;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Clock::adapt() (deprecated): a legacy clock closure read as a Clock',
   test: function () {
      $timestamp = 1_700_000_000.75;
      $Adapter = Clock::adapt(static function () use (&$timestamp) {
         return $timestamp;
      });

      yield assert(
         assertion: $Adapter instanceof Clock,
         description: 'the adapter is a Clock'
      );
      yield assert(
         assertion: $Adapter->time === 1_700_000_000,
         description: '$time is (int) $closure() — the reading the legacy options had'
      );
      yield assert(
         assertion: $Adapter->now === 1_700_000_000.75,
         description: '$now is the closure value as float seconds'
      );
      yield assert(
         assertion: $Adapter->tick === (int) (1_700_000_000.75 * 1_000_000_000),
         description: '$tick is the closure value in nanoseconds'
      );

      $timestamp += 2;
      yield assert(
         assertion: $Adapter->time === 1_700_000_002 && $Adapter->now === 1_700_000_002.75,
         description: 'each reading calls the closure again'
      );

      $Integer = Clock::adapt(static fn (): int => 42);
      yield assert(
         assertion: $Integer->time === 42 && $Integer->now === 42.0 && $Integer->tick === 42_000_000_000,
         description: 'an int closure reads the same in every unit'
      );
   }
);
