<?php

use Bootgly\ABI\Differ\Diff\Chunk;
use Bootgly\ABI\Differ\Diff\Line;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Diff\Chunk: ranges, lines, iteration, update()',
   test: function () {
      $Chunk = new Chunk(1, 2, 3, 4, [new Line(Line::ADDED, 'x')]);

      yield assert(
         assertion: $Chunk->start === 1 && $Chunk->startRange === 2,
         description: 'start/startRange'
      );
      yield assert(
         assertion: $Chunk->end === 3 && $Chunk->endRange === 4,
         description: 'end/endRange'
      );
      yield assert(
         assertion: count($Chunk->lines) === 1,
         description: 'lines count'
      );

      // @ Iteration
      $iterated = 0;
      foreach ($Chunk as $_) { $iterated++; }
      yield assert(
         assertion: $iterated === 1,
         description: 'IteratorAggregate yields lines'
      );

      // @ update()
      $Chunk->update([new Line(Line::REMOVED, 'a'), new Line(Line::ADDED, 'b')]);
      yield assert(
         assertion: count($Chunk->lines) === 2 && $Chunk->lines[0]->removed,
         description: 'update() replaces lines'
      );
   }
);
