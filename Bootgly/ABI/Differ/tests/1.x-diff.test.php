<?php

use Bootgly\ABI\Differ\Diff;
use Bootgly\ABI\Differ\Diff\Chunk;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Diff: from/to/chunks via asymmetric visibility',
   test: function () {
      $Diff = new Diff('a/file.txt', 'b/file.txt');

      yield assert(
         assertion: $Diff->from === 'a/file.txt',
         description: 'from'
      );
      yield assert(
         assertion: $Diff->to === 'b/file.txt',
         description: 'to'
      );
      yield assert(
         assertion: $Diff->chunks === [],
         description: 'default chunks empty'
      );

      $Diff->update([new Chunk(1, 1, 1, 1)]);
      yield assert(
         assertion: count($Diff->chunks) === 1,
         description: 'update() sets chunks'
      );

      $iterated = 0;
      foreach ($Diff as $_) { $iterated++; }
      yield assert(
         assertion: $iterated === 1,
         description: 'IteratorAggregate yields chunks'
      );
   }
);
