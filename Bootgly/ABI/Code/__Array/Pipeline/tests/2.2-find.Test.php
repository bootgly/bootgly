<?php

use Bootgly\ABI\Code\__Array\Pipeline;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should return the first survivor and stop there',
   test: function () {
      $Double = static fn (int $value): int => $value * 2;
      $Over = static fn (int $value): bool => $value > 6;

      // ! The first survivor, transformed by the stages it passed through
      yield assert(
         assertion: new Pipeline([1, 2, 3, 4, 5])->map($Double)->filter($Over)->find() === 8,
         description: 'find() returns the first element surviving every stage'
      );

      // ! Nothing survives
      yield assert(
         assertion: new Pipeline([1, 2])->map($Double)->filter($Over)->find() === null,
         description: 'find() returns null when nothing survives'
      );

      // ! Empty source
      yield assert(
         assertion: new Pipeline([])->filter($Over)->find() === null,
         description: 'find() returns null for an empty source'
      );

      // ! No stages — the first element
      yield assert(
         assertion: new Pipeline([7, 8, 9])->find() === 7
            && new Pipeline(['a' => 'x', 'b' => 'y'])->find() === 'x',
         description: 'find() with no stages returns the first element'
      );

      // ! It really stops — the specialized map->filter shape
      $seen = 0;
      $Count = static function (int $value) use (&$seen): int {
         $seen++;

         return $value * 2;
      };

      $found = new Pipeline([1, 2, 3, 4, 5, 6, 7, 8, 9, 10])->map($Count)->filter($Over)->find();

      yield assert(
         assertion: $found === 8 && $seen === 4,
         description: 'The map->filter shape stops at the first survivor'
      );

      // ! It really stops — the generic shape (three stages)
      $seen = 0;
      $Always = static fn (int $value): bool => true;

      $found = new Pipeline([1, 2, 3, 4, 5, 6, 7, 8, 9, 10])
         ->filter($Always)
         ->map($Count)
         ->filter($Over)
         ->find();

      yield assert(
         assertion: $found === 8 && $seen === 4,
         description: 'The generic shape stops at the first survivor too'
      );

      // ! A surviving null is indistinguishable from no survivor — the documented limit
      yield assert(
         assertion: new Pipeline([null])->find() === null,
         description: 'A surviving null reads the same as no survivor'
      );
   }
);
