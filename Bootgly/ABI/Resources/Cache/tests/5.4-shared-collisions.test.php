<?php

use Bootgly\ABI\Resources\Cache;
use Bootgly\ABI\Resources\Cache\Drivers\Shared;
use Bootgly\ACI\Tests\Doubles\Fake\Clock;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Cache(Shared): CRC32 collisions preserve full-key isolation',
   skip: extension_loaded('sysvshm') === false || extension_loaded('sysvsem') === false,
   test: function () {
      $Clock = new Clock(12_345);
      $Cache = new Cache([
         'driver' => 'shared',
         'prefix' => 'ratelimit:',
         'segment' => random_int(10_000_000, 2_000_000_000),
         'size' => 262_144,
         'clock' => static fn (): int => (int) $Clock->now,
      ]);
      $Driver = $Cache->Driver;

      $principalA = 'b97186618aa1434e';
      $principalB = '2f6843fd71907689';
      $keyA = "{$principalA}:12345";
      $keyB = "{$principalB}:12345";
      $wireA = "ratelimit:{$keyA}";
      $wireB = "ratelimit:{$keyB}";
      $CRCA = crc32($wireA);
      $CRCB = crc32($wireB);

      try {
         yield assert(
            assertion: $wireA !== $wireB
               && $CRCA === $CRCB
               && crc32($principalA) === crc32($principalB),
            description: 'Fixture collides for both value slots and tag slots'
         );

         // # Store / fetch / check
         $Cache->store($keyA, 'A', 0, [$principalA]);
         $Cache->store($keyB, 'B', 0, [$principalB]);
         yield assert(
            assertion: $Cache->fetch($keyA) === 'A'
               && $Cache->fetch($keyB) === 'B'
               && $Cache->check($keyA) === true
               && $Cache->check($keyB) === true,
            description: 'Colliding full keys retain independent values'
         );

         // # Tags — the tag names themselves also collide at CRC32.
         $Cache->invalidate($principalA);
         yield assert(
            assertion: $Cache->fetch($keyA) === null && $Cache->fetch($keyB) === 'B',
            description: 'Invalidating one colliding tag preserves the other key'
         );
         $Cache->invalidate($principalB);
         yield assert(
            assertion: $Cache->fetch($keyB) === null,
            description: 'The second colliding tag remains independently invalidatable'
         );

         // # Atomic counters
         $counts = [
            $Cache->increment($keyA),
            $Cache->increment($keyB),
            $Cache->increment($keyA),
            $Cache->increment($keyB),
         ];
         yield assert(
            assertion: $counts === [1, 1, 2, 2],
            description: 'Alternating colliding counters advance independently'
         );

         // # Delete
         $Cache->delete($keyA);
         yield assert(
            assertion: $Cache->fetch($keyA) === null && $Cache->fetch($keyB) === 2,
            description: 'Deleting one colliding key preserves its neighbor'
         );
         $Cache->delete($keyB);

         // # TTL / purge
         $Cache->store($keyA, 'short', 5);
         $Cache->store($keyB, 'long', 10);
         $Clock->advance(6);
         yield assert(
            assertion: $Cache->purge() === 1
               && $Cache->fetch($keyA) === null
               && $Cache->fetch($keyB) === 'long'
               && $Cache->remain($keyB) === 4,
            description: 'Purge removes only the expired record inside a collision bucket'
         );

         $Cache->clear();
         yield assert(
            assertion: $Cache->fetch($keyB) === null,
            description: 'Clear removes the remaining collision bucket'
         );
      }
      finally {
         if ($Driver instanceof Shared) {
            $Driver->destroy();
         }
      }
   }
);
