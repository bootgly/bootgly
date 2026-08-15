<?php

use Bootgly\ABI\Code\__Array\Pipeline;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should materialize every recorded shape in one pass',
   test: function () {
      $Double = static fn (int $value): int => $value * 2;
      $Even = static fn (int $value): bool => $value % 2 === 0;
      $Increment = static fn (int $value): int => $value + 1;

      $source = [1, 2, 3, 4, 5, 6];

      // ! No stages — the source, re-indexed
      yield assert(
         assertion: new Pipeline($source)->collect() === $source
            && new Pipeline(['a' => 1, 'b' => 2])->collect() === [1, 2],
         description: 'A pipeline with no stages returns the source as a list'
      );

      // ! One stage — map only, then filter only
      yield assert(
         assertion: new Pipeline($source)->map($Double)->collect() === [2, 4, 6, 8, 10, 12],
         description: 'The map-only shape materializes every transformed element'
      );

      yield assert(
         assertion: new Pipeline($source)->filter($Even)->collect() === [2, 4, 6],
         description: 'The filter-only shape materializes the survivors'
      );

      // ! Two stages — both specialized orders
      yield assert(
         assertion: new Pipeline($source)->map($Double)->filter($Even)->collect() === [2, 4, 6, 8, 10, 12],
         description: 'The map->filter shape tests the transformed value'
      );

      yield assert(
         assertion: new Pipeline($source)->filter($Even)->map($Double)->collect() === [4, 8, 12],
         description: 'The filter->map shape transforms the survivors'
      );

      // ! Two stages of the same kind fall back to the generic pass
      yield assert(
         assertion: new Pipeline($source)->map($Double)->map($Increment)->collect() === [3, 5, 7, 9, 11, 13],
         description: 'Two transforms fall back to the generic pass and stay ordered'
      );

      // ! Three stages — the generic pass
      yield assert(
         assertion: new Pipeline($source)->map($Double)->filter($Even)->map($Increment)->collect()
            === [3, 5, 7, 9, 11, 13],
         description: 'A three-stage chain runs in the generic pass'
      );

      // ! Collecting twice is idempotent — stages are not consumed
      $Pipeline = new Pipeline($source)->map($Double)->filter($Even);

      yield assert(
         assertion: $Pipeline->collect() === $Pipeline->collect(),
         description: 'Collecting does not consume the recorded stages'
      );

      // ! An empty source is empty at every shape
      yield assert(
         assertion: new Pipeline([])->collect() === []
            && new Pipeline([])->map($Double)->collect() === []
            && new Pipeline([])->map($Double)->filter($Even)->collect() === [],
         description: 'Every shape returns an empty list for an empty source'
      );
   }
);
