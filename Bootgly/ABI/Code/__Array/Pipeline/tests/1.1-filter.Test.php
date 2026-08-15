<?php

use Bootgly\ABI\Code\__Array\Pipeline;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should record a test every element must pass',
   test: function () {
      $Even = static fn (int $value): bool => $value % 2 === 0;
      $Positive = static fn (int $value): bool => $value > 0;

      // ! Survivors only, re-indexed
      yield assert(
         assertion: (new Pipeline([1, 2, 3, 4, 5, 6]))->filter($Even)->collect() === [2, 4, 6],
         description: 'Only elements passing the test survive'
      );

      // ! Two tests both have to pass
      yield assert(
         assertion: (new Pipeline([-4, -2, 1, 2, 3, 4]))->filter($Even)->filter($Positive)->collect() === [2, 4],
         description: 'Recorded tests are combined, not replaced'
      );

      // ! Nothing survives
      yield assert(
         assertion: (new Pipeline([1, 3, 5]))->filter($Even)->collect() === [],
         description: 'A pipeline where nothing survives yields an empty list'
      );

      // ! Everything survives
      yield assert(
         assertion: (new Pipeline([2, 4]))->filter($Even)->collect() === [2, 4],
         description: 'A pipeline where everything survives keeps every element'
      );

      // ! Keys are dropped, so a sparse survivor set is still a list
      yield assert(
         assertion: (new Pipeline(['x' => 1, 'y' => 2, 'z' => 4]))->filter($Even)->collect() === [2, 4],
         description: 'Survivors are re-indexed from zero'
      );

      // ! filter -> map is a distinct shape from map -> filter
      $Double = static fn (int $value): int => $value * 2;

      yield assert(
         assertion: (new Pipeline([1, 2, 3, 4]))->filter($Even)->map($Double)->collect() === [4, 8],
         description: 'filter() before map() tests the untransformed value'
      );

      yield assert(
         assertion: (new Pipeline([1, 2, 3, 4]))->map($Double)->filter($Even)->collect() === [2, 4, 6, 8],
         description: 'map() before filter() tests the transformed value'
      );
   }
);
