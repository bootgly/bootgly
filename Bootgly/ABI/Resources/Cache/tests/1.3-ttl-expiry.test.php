<?php

use function sys_get_temp_dir;
use function uniqid;

use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Doubles\Fake\Clock;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Cache(File): TTL expiry evaluated against an injected clock',
   test: function () {
      $dir = sys_get_temp_dir() . '/bootgly-cache-test-' . uniqid();
      $Clock = new Clock(1_000_000);
      $Cache = new Cache([
         'driver' => 'file',
         'path' => $dir,
         'prefix' => 't:',
         'clock' => static fn (): int => (int) $Clock->now,
      ]);

      $Cache->store('k', 'v', 5);

      yield assert(
         assertion: $Cache->fetch('k') === 'v',
         description: 'Value present before TTL elapses'
      );
      yield assert(
         assertion: $Cache->check('k') === true,
         description: 'check() true before TTL elapses'
      );

      $Clock->advance(6);

      yield assert(
         assertion: $Cache->fetch('k') === null,
         description: 'Value gone after TTL elapses'
      );
      yield assert(
         assertion: $Cache->check('k') === false,
         description: 'check() false after TTL elapses'
      );

      $Cache->clear();
   }
);
