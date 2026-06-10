<?php

use function sys_get_temp_dir;
use function uniqid;

use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Cache(File): resolve computes on miss, reuses on hit, treats stored null as miss',
   test: function () {
      $dir = sys_get_temp_dir() . '/bootgly-cache-test-' . uniqid();
      $Cache = new Cache(['driver' => 'file', 'path' => $dir, 'prefix' => 't:']);

      // @ Miss: computes and stores
      $computed = 0;
      $value = $Cache->resolve('answer', 0, function () use (&$computed) {
         $computed++;

         return 42;
      });

      yield assert(
         assertion: $value === 42 && $computed === 1,
         description: 'Miss computes the value once'
      );
      yield assert(
         assertion: $Cache->fetch('answer') === 42,
         description: 'Computed value was stored'
      );

      // @ Hit: returns the cached value without recomputing
      $value = $Cache->resolve('answer', 0, function () use (&$computed) {
         $computed++;

         return 99;
      });

      yield assert(
         assertion: $value === 42 && $computed === 1,
         description: 'Hit returns cached value without recomputing'
      );

      // @ Stored null is indistinguishable from a miss — recomputed by design
      $Cache->store('void', null);
      $recomputed = 0;
      $value = $Cache->resolve('void', 0, function () use (&$recomputed) {
         $recomputed++;

         return 'fresh';
      });

      yield assert(
         assertion: $value === 'fresh' && $recomputed === 1,
         description: 'Stored null is treated as a miss and recomputed'
      );

      // @ Tags flow through resolve to the stored entry
      $Cache->resolve('tagged', 0, fn () => 'v', ['group']);
      $Cache->invalidate('group');

      yield assert(
         assertion: $Cache->fetch('tagged') === null,
         description: 'Tags passed to resolve are honored by invalidate'
      );

      $Cache->clear();
   }
);
