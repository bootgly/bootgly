<?php

namespace Bootgly\ABI\Debugging\Data\Vars;


use function assert;
use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification;


class DumpDeep
{
   public int $x = 1;
}

return new Specification(
   description: 'It should cap depth, items and string length with visible markers',
   test: function () {
      $Dumper = new Dumper('plain');
      $Dumper->depth = 1;
      $Dumper->items = 2;
      $Dumper->strings = 4;

      // @ Depth cap — arrays
      $expected = <<<'DUMP'
      array:1 [
         'deep' => [ … ]
      ]
      DUMP;
      yield assert(
         assertion: $Dumper->dump(['deep' => ['deeper' => ['deepest' => 1]]]) === $expected,
         description: 'Arrays past the depth cap collapse to [ … ]'
      );

      // @ Depth cap — objects
      $Capped = new Dumper('plain');
      $Capped->depth = 0;

      yield assert(
         assertion: $Capped->dump(new DumpDeep) === 'Bootgly\ABI\Debugging\Data\Vars\DumpDeep { … }',
         description: 'Objects past the depth cap collapse to Name { … }'
      );

      // @ Items cap
      $expected = <<<'DUMP'
      array:5 [
         0 => 1
         1 => 2
         … +3 more
      ]
      DUMP;
      yield assert(
         assertion: $Dumper->dump([1, 2, 3, 4, 5]) === $expected,
         description: 'Containers past the items cap collapse the tail into … +N more'
      );

      // @ String cap — raw chars counted, multibyte-safe cut
      yield assert(
         assertion: $Dumper->dump('abcdefghij') === "'abcd…' (+6)",
         description: 'Long strings truncate with a remaining-chars note'
      );

      yield assert(
         assertion: $Dumper->dump('áéíóú987') === "'áéíó…' (+4)"
            && str_contains($Dumper->dump('áéíóú987'), 'áéíó') === true,
         description: 'Multibyte strings cut on char boundaries — no broken UTF-8'
      );
   }
);
