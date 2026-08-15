<?php

use Bootgly\ABI\Code\__Array\Pipeline;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should answer whether anything survives, stopping at the first one',
   test: function () {
      $Double = static fn (int $value): int => $value * 2;
      $Over = static fn (int $value): bool => $value > 6;

      yield assert(
         assertion: (new Pipeline([1, 2, 3, 4, 5]))->map($Double)->filter($Over)->check() === true,
         description: 'check() is true when at least one element survives'
      );

      yield assert(
         assertion: (new Pipeline([1, 2]))->map($Double)->filter($Over)->check() === false,
         description: 'check() is false when nothing survives'
      );

      yield assert(
         assertion: (new Pipeline([]))->filter($Over)->check() === false
            && (new Pipeline([]))->check() === false,
         description: 'check() is false for an empty source'
      );

      yield assert(
         assertion: (new Pipeline([1]))->check() === true,
         description: 'check() with no stages is true for a non-empty source'
      );

      // ! It stops at the first survivor — specialized shape
      $seen = 0;
      $Count = static function (int $value) use (&$seen): int {
         $seen++;

         return $value * 2;
      };

      $answer = (new Pipeline([1, 2, 3, 4, 5, 6, 7, 8, 9, 10]))->map($Count)->filter($Over)->check();

      yield assert(
         assertion: $answer === true && $seen === 4,
         description: 'The map->filter shape stops as soon as one survives'
      );

      // ! It stops at the first survivor — generic shape
      $seen = 0;
      $Always = static fn (int $value): bool => true;

      $answer = (new Pipeline([1, 2, 3, 4, 5, 6, 7, 8, 9, 10]))
         ->filter($Always)
         ->map($Count)
         ->filter($Over)
         ->check();

      yield assert(
         assertion: $answer === true && $seen === 4,
         description: 'The generic shape stops as soon as one survives'
      );

      // ! A surviving null is still a survivor — what find() cannot express
      yield assert(
         assertion: (new Pipeline([null]))->check() === true,
         description: 'check() reports a surviving null that find() cannot distinguish'
      );
   }
);
