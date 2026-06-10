<?php

use function sys_get_temp_dir;
use function uniqid;

use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Cache(File): atomic increment/decrement counter semantics',
   test: function () {
      $dir = sys_get_temp_dir() . '/bootgly-cache-test-' . uniqid();
      $Cache = new Cache(['driver' => 'file', 'path' => $dir, 'prefix' => 'c:']);

      yield assert(
         assertion: $Cache->increment('hits') === 1,
         description: 'First increment creates counter at 1'
      );
      yield assert(
         assertion: $Cache->increment('hits') === 2,
         description: 'Second increment yields 2'
      );
      yield assert(
         assertion: $Cache->increment('hits', 5) === 7,
         description: 'Increment by step adds the step'
      );
      yield assert(
         assertion: $Cache->decrement('hits', 3) === 4,
         description: 'Decrement subtracts the step'
      );
      yield assert(
         assertion: $Cache->fetch('hits') === 4,
         description: 'Counter persists its value for fetch()'
      );

      $Cache->clear();
   }
);
