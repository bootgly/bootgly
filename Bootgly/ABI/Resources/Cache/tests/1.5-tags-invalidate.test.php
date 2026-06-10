<?php

use function sys_get_temp_dir;
use function uniqid;

use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Cache(File): tag-based invalidation drops only tagged keys',
   test: function () {
      $dir = sys_get_temp_dir() . '/bootgly-cache-test-' . uniqid();
      $Cache = new Cache(['driver' => 'file', 'path' => $dir, 'prefix' => 't:']);

      $Cache->store('a', 'A', 0, ['group']);
      $Cache->store('b', 'B', 0, ['group']);
      $Cache->store('c', 'C', 0, ['other']);

      $Cache->invalidate('group');

      yield assert(
         assertion: $Cache->fetch('a') === null,
         description: 'Tagged key a invalidated'
      );
      yield assert(
         assertion: $Cache->fetch('b') === null,
         description: 'Tagged key b invalidated'
      );
      yield assert(
         assertion: $Cache->fetch('c') === 'C',
         description: 'Differently-tagged key c survives'
      );

      $Cache->clear();
   }
);
