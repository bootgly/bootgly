<?php

use function sys_get_temp_dir;
use function uniqid;
use stdClass;

use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Cache(File): store/fetch round-trips for scalars, arrays and objects',
   test: function () {
      $dir = sys_get_temp_dir() . '/bootgly-cache-test-' . uniqid();
      $Cache = new Cache(['driver' => 'file', 'path' => $dir, 'prefix' => 't:']);

      $Cache->store('s', 'string');
      $Cache->store('i', 42);
      $Cache->store('arr', ['a' => 1, 'b' => [2, 3]]);

      $Object = new stdClass();
      $Object->name = 'bootgly';
      $Cache->store('o', $Object);

      yield assert(
         assertion: $Cache->fetch('s') === 'string',
         description: 'String value round-trips'
      );
      yield assert(
         assertion: $Cache->fetch('i') === 42,
         description: 'Integer value round-trips'
      );
      yield assert(
         assertion: $Cache->fetch('arr') === ['a' => 1, 'b' => [2, 3]],
         description: 'Nested array round-trips'
      );

      $Fetched = $Cache->fetch('o');
      yield assert(
         assertion: $Fetched instanceof stdClass && $Fetched->name === 'bootgly',
         description: 'Object value round-trips'
      );
      yield assert(
         assertion: $Cache->fetch('missing') === null,
         description: 'Missing key returns null'
      );

      $Cache->clear();
   }
);
