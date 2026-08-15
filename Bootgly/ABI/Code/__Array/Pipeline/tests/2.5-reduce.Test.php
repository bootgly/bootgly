<?php

use Bootgly\ABI\Code\__Array\Pipeline;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should fold the survivors inside the same pass',
   test: function () {
      $Double = static fn (int $value): int => $value * 2;
      $Even = static fn (int $value): bool => $value % 2 === 0;
      $Sum = static fn (int $carry, int $value): int => $carry + $value;

      $source = [1, 2, 3, 4, 5];

      yield assert(
         assertion: (new Pipeline($source))->map($Double)->reduce($Sum, 0) === 30,
         description: 'reduce() folds every transformed element'
      );

      yield assert(
         assertion: (new Pipeline($source))->filter($Even)->reduce($Sum, 0)
            === array_reduce(array_filter($source, $Even), $Sum, 0),
         description: 'reduce() agrees with array_reduce over the filtered array'
      );

      // ! The initial value is the carry the fold starts from
      yield assert(
         assertion: (new Pipeline($source))->filter($Even)->reduce($Sum, 100) === 106,
         description: 'reduce() starts from the initial carry'
      );

      // ! Nothing survives — the initial value comes straight back
      yield assert(
         assertion: (new Pipeline([1, 3]))->filter($Even)->reduce($Sum, 42) === 42,
         description: 'reduce() returns the initial carry when nothing survives'
      );

      yield assert(
         assertion: (new Pipeline([]))->reduce($Sum, 7) === 7,
         description: 'reduce() returns the initial carry for an empty source'
      );

      // ! The default carry is null
      $Concat = static fn (null|string $carry, string $value): string => $carry . $value;

      yield assert(
         assertion: (new Pipeline(['a', 'b', 'c']))->reduce($Concat) === 'abc',
         description: 'reduce() defaults the carry to null'
      );

      // ! Folding does not consume the stages
      $Pipeline = (new Pipeline($source))->filter($Even);

      yield assert(
         assertion: $Pipeline->reduce($Sum, 0) === $Pipeline->reduce($Sum, 0),
         description: 'Folding twice yields the same result'
      );
   }
);
