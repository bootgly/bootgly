<?php

use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Doubles\Fake\Clock;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Cache(Memory): in-process array contract — store/fetch, counters, tags, TTL, purge, clear',
   test: function () {
      $Clock = new Clock(1_000_000);
      $Cache = new Cache([
         'driver' => 'memory',
         'prefix' => 'm:',
         'clock' => static fn (): int => (int) $Clock->now,
      ]);
      $Cache->clear();

      // # Store / fetch
      $Cache->store('s', 'string');
      $Cache->store('i', 42);
      $Cache->store('arr', ['a' => 1, 'b' => [2, 3]]);
      yield assert(
         assertion: $Cache->fetch('s') === 'string'
            && $Cache->fetch('i') === 42
            && $Cache->fetch('arr') === ['a' => 1, 'b' => [2, 3]],
         description: 'Scalars and nested arrays round-trip in process'
      );
      yield assert(
         assertion: $Cache->fetch('missing') === null && $Cache->check('s') === true,
         description: 'Miss returns null; check() reflects presence'
      );

      // # check() / idempotent delete()
      yield assert(
         assertion: $Cache->delete('s') === true && $Cache->delete('gone') === true,
         description: 'delete() succeeds for present and absent keys'
      );
      yield assert(
         assertion: $Cache->check('s') === false && $Cache->fetch('s') === null,
         description: 'Deleted key reports missing and fetches null'
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

      // # TTL + remain() against the injected clock
      $Cache->store('x', 1, 5);
      $Cache->store('y', 2, 0);
      yield assert(
         assertion: $Cache->remain('x') === 5 && $Cache->remain('y') === -1 && $Cache->remain('z') === -2,
         description: 'remain() reports TTL left, -1 for permanent, -2 for missing'
      );

      $Clock->advance(6);
      yield assert(
         assertion: $Cache->fetch('x') === null && $Cache->fetch('y') === 2,
         description: 'Expired entry reads as miss; permanent entry survives'
      );
      yield assert(
         assertion: $Cache->purge() === 0,
         description: 'purge() reclaims nothing once a lazy fetch already dropped the expired key'
      );

      // # purge() on an untouched expired entry
      $Cache->store('p', 9, 5);
      $Clock->advance(6);
      yield assert(
         assertion: $Cache->purge() === 1,
         description: 'purge() reclaims exactly the expired entry'
      );

      // # Clear
      $Cache->clear();
      yield assert(
         assertion: $Cache->fetch('y') === null && $Cache->fetch('n') === null,
         description: 'clear() empties the store'
      );
   }
);
