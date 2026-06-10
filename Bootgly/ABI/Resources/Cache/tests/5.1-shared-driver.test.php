<?php

use function extension_loaded;
use function random_int;

use Bootgly\ABI\Resources\Cache;
use Bootgly\ABI\Resources\Cache\Drivers\Shared;
use Bootgly\ACI\Tests\Doubles\Fake\Clock;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Cache(Shared): contract over a System V segment (requires ext-sysvshm + ext-sysvsem)',
   skip: extension_loaded('sysvshm') === false || extension_loaded('sysvsem') === false,
   test: function () {
      $Clock = new Clock(3_000_000);
      $Cache = new Cache([
         'driver' => 'shared',
         'prefix' => 's:',
         'segment' => random_int(200_000, 9_000_000),
         'size' => 262_144,
         'clock' => static fn (): int => (int) $Clock->now,
      ]);
      $Cache->clear();

      // # Store / fetch
      $Cache->store('s', 'string');
      $Cache->store('i', 42);
      yield assert(
         assertion: $Cache->fetch('s') === 'string' && $Cache->fetch('i') === 42,
         description: 'Values round-trip through shared memory'
      );
      yield assert(
         assertion: $Cache->fetch('missing') === null && $Cache->check('s') === true,
         description: 'Miss returns null; check() reflects presence'
      );

      // # Counters
      yield assert(
         assertion: $Cache->increment('n') === 1 && $Cache->increment('n', 6) === 7,
         description: 'increment() creates and advances'
      );
      yield assert(
         assertion: $Cache->decrement('n', 3) === 4,
         description: 'decrement() subtracts'
      );

      // # Tags
      $Cache->store('a', 'A', 0, ['group']);
      $Cache->store('b', 'B', 0, ['other']);
      $Cache->invalidate('group');
      yield assert(
         assertion: $Cache->fetch('a') === null && $Cache->fetch('b') === 'B',
         description: 'Tag invalidation drops only tagged keys'
      );

      // # TTL + purge against the injected clock
      $Cache->store('x', 1, 5);
      $Cache->store('y', 2, 0);
      $Clock->advance(6);
      yield assert(
         assertion: $Cache->fetch('x') === null && $Cache->fetch('y') === 2,
         description: 'Expired entry reads as miss; permanent entry survives'
      );
      yield assert(
         assertion: $Cache->purge() === 1,
         description: 'purge() reclaims exactly the expired entry'
      );

      // # Clear
      $Cache->clear();
      yield assert(
         assertion: $Cache->fetch('y') === null && $Cache->fetch('n') === null,
         description: 'clear() empties the segment'
      );

      // @ Release the OS segment + semaphore
      $Driver = $Cache->Driver;
      if ($Driver instanceof Shared === true) {
         $Driver->destroy();
      }
   }
);
