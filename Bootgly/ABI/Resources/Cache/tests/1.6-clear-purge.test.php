<?php

use function sys_get_temp_dir;
use function uniqid;

use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Doubles\Fake\Clock;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Cache(File): purge() evicts only expired entries, clear() empties all',
   test: function () {
      $dir = sys_get_temp_dir() . '/bootgly-cache-test-' . uniqid();
      $Clock = new Clock(2_000_000);
      $Cache = new Cache([
         'driver' => 'file',
         'path' => $dir,
         'prefix' => 'p:',
         'clock' => static fn (): int => (int) $Clock->now,
      ]);

      $Cache->store('x', 1, 5);  // expires
      $Cache->store('y', 2, 0);  // forever

      $Clock->advance(10);

      yield assert(
         assertion: $Cache->purge() === 1,
         description: 'Exactly one expired entry purged'
      );
      yield assert(
         assertion: $Cache->fetch('y') === 2,
         description: 'Non-expired entry survives purge'
      );

      $Cache->store('z', 3);
      $Cache->clear();

      yield assert(
         assertion: $Cache->fetch('y') === null && $Cache->fetch('z') === null,
         description: 'clear() empties the cache'
      );
   }
);
