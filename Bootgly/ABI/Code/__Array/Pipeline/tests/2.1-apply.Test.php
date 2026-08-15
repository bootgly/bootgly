<?php

use Bootgly\ABI\Code\__Array\Pipeline;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should run recorded stages over any array, repeatedly',
   test: function () {
      $Double = static fn (int $value): int => $value * 2;
      $Even = static fn (int $value): bool => $value % 2 === 0;

      // ! Built once, applied many times, with no cross-contamination
      $Pipeline = new Pipeline()->map($Double)->filter($Even);

      $first = $Pipeline->apply([1, 2, 3]);
      $second = $Pipeline->apply([10, 20]);

      yield assert(
         assertion: $first === [2, 4, 6] && $second === [20, 40],
         description: 'One pipeline applies to several arrays independently'
      );

      yield assert(
         assertion: $Pipeline->apply([1, 2, 3]) === $first,
         description: 'Applying again yields the same result as the first time'
      );

      // ! A source given to the constructor is ignored by apply()
      $Sourced = new Pipeline([100, 200])->map($Double);

      yield assert(
         assertion: $Sourced->apply([1, 2]) === [2, 4] && $Sourced->collect() === [200, 400],
         description: 'apply() uses its argument while collect() uses the source'
      );

      // ! A source-less pipeline collects an empty list
      yield assert(
         assertion: new Pipeline()->map($Double)->collect() === [],
         description: 'A pipeline built without a source collects nothing'
      );

      // ! Every shape is reachable through apply()
      yield assert(
         assertion: new Pipeline()->apply([1, 2]) === [1, 2]
            && new Pipeline()->filter($Even)->apply([1, 2, 3, 4]) === [2, 4]
            && new Pipeline()->filter($Even)->map($Double)->apply([1, 2, 3, 4]) === [4, 8],
         description: 'apply() dispatches the same shapes collect() does'
      );

      // ! Applying an empty array is empty
      yield assert(
         assertion: $Pipeline->apply([]) === [],
         description: 'Applying to an empty array yields an empty list'
      );
   }
);
