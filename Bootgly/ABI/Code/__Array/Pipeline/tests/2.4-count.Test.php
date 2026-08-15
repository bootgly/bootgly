<?php

use Bootgly\ABI\Code\__Array\Pipeline;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should count survivors without materializing them',
   test: function () {
      $Double = static fn (int $value): int => $value * 2;
      $Over = static fn (int $value): bool => $value > 6;
      $Even = static fn (int $value): bool => $value % 2 === 0;

      $source = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];

      yield assert(
         assertion: new Pipeline($source)->map($Double)->filter($Over)->count() === 7,
         description: 'count() returns how many elements survive every stage'
      );

      yield assert(
         assertion: new Pipeline($source)->filter($Even)->count()
            === count(array_filter($source, $Even)),
         description: 'count() agrees with count(array_filter(...))'
      );

      yield assert(
         assertion: new Pipeline([1, 3, 5])->filter($Even)->count() === 0,
         description: 'count() is zero when nothing survives'
      );

      yield assert(
         assertion: new Pipeline([])->filter($Even)->count() === 0
            && new Pipeline([])->count() === 0,
         description: 'count() is zero for an empty source'
      );

      yield assert(
         assertion: new Pipeline($source)->count() === 10
            && new Pipeline(['a' => 1, 'b' => 2])->count() === 2,
         description: 'count() with no stages counts the source'
      );

      // ! It walks every element — no early exit here, by definition
      $seen = 0;
      $Count = static function (int $value) use (&$seen): bool {
         $seen++;

         return true;
      };

      yield assert(
         assertion: new Pipeline($source)->filter($Count)->count() === 10 && $seen === 10,
         description: 'count() tests every element'
      );
   }
);
