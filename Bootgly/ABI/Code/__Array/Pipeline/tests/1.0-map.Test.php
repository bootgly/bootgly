<?php

use Bootgly\ABI\Code\__Array\Pipeline;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should record a transform without running it',
   test: function () {
      $ran = 0;
      $Double = static function (int $value) use (&$ran): int {
         $ran++;

         return $value * 2;
      };

      // ! Recording runs nothing
      $Pipeline = new Pipeline([1, 2, 3])->map($Double);

      yield assert(
         assertion: $ran === 0,
         description: 'map() does not invoke the transform when recorded'
      );

      yield assert(
         assertion: $Pipeline->collect() === [2, 4, 6] && $ran === 3,
         description: 'The transform runs once per element at the terminal'
      );

      // ! Chaining returns the same instance, so stages accumulate
      $Pipeline = new Pipeline([1, 2, 3]);

      yield assert(
         assertion: $Pipeline->map($Double) === $Pipeline,
         description: 'map() returns the same pipeline for chaining'
      );

      // ! Two transforms compose in the order recorded
      $Increment = static fn (int $value): int => $value + 1;

      yield assert(
         assertion: new Pipeline([1, 2, 3])->map($Increment)->map(static fn (int $v): int => $v * 10)->collect()
            === [20, 30, 40],
         description: 'Recorded transforms apply in order'
      );

      // ! An empty source produces an empty result
      yield assert(
         assertion: new Pipeline([])->map($Increment)->collect() === [],
         description: 'Mapping an empty array yields an empty list'
      );

      // ! Keys are not carried — the result is always a list
      yield assert(
         assertion: new Pipeline(['a' => 1, 'b' => 2])->map($Increment)->collect() === [2, 3],
         description: 'A mapped result is re-indexed as a list'
      );
   }
);
