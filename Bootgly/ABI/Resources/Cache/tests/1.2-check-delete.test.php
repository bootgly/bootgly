<?php

use function sys_get_temp_dir;
use function uniqid;

use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Cache(File): check() presence and idempotent delete()',
   test: function () {
      $dir = sys_get_temp_dir() . '/bootgly-cache-test-' . uniqid();
      $Cache = new Cache(['driver' => 'file', 'path' => $dir, 'prefix' => 't:']);

      $Cache->store('k', 'v');

      yield assert(
         assertion: $Cache->check('k') === true,
         description: 'Stored key reports present'
      );
      yield assert(
         assertion: $Cache->check('nope') === false,
         description: 'Absent key reports missing'
      );

      yield assert(
         assertion: $Cache->delete('k') === true,
         description: 'Delete of present key succeeds'
      );
      yield assert(
         assertion: $Cache->check('k') === false,
         description: 'Deleted key reports missing'
      );
      yield assert(
         assertion: $Cache->fetch('k') === null,
         description: 'Deleted key fetches null'
      );
      yield assert(
         assertion: $Cache->delete('alreadygone') === true,
         description: 'Delete of absent key is idempotent'
      );

      $Cache->clear();
   }
);
