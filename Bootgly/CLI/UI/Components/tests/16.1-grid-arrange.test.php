<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function count;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should distribute weighted tracks exactly and assign frame geometry on place',
   test: function () {
      // ! Grid over an in-memory host stream — btop-like weighted layout
      $Host = new Output('php://memory');

      $Grid = new Grid($Host);
      $Grid->width = 80;
      $Grid->height = 30;
      $Grid->rows = [1, 2];
      $Grid->columns = [2, 1, 1];

      $A = new Frame($Host);
      $B = new Frame($Host);
      $C = new Frame($Host);

      // @ Placement assigns the frame geometry immediately
      $Grid->place($A, row: 1, column: 1, colspan: 3);

      yield assert(
         assertion: $A->row === 1 && $A->column === 1 && $A->width === 80 && $A->height === 10,
         description: 'Placing assigns the spanned geometry before any render'
      );

      $Grid->place($B, row: 2, column: 1, colspan: 2);
      $Grid->place($C, row: 2, column: 3);

      yield assert(
         assertion: $B->row === 11 && $B->column === 1 && $B->width === 60 && $B->height === 20,
         description: 'Weighted rows offset the second band below the first track'
      );
      yield assert(
         assertion: $C->row === 11 && $C->column === 61 && $C->width === 20 && $C->height === 20,
         description: 'Column offsets accumulate the preceding track sizes'
      );

      // @ Odd spaces — the largest remainders absorb the leftover, sums stay exact
      $Grid->width = 79;
      $Grid->arrange();

      yield assert(
         assertion: $A->width === 79 && $B->width === 59 && $C->width === 20
            && $B->width + $C->width === 79,
         description: 'Track sizes always sum exactly to the rectangle on odd spaces'
      );

      // @ Gap — trimmed only off the sides that face another cell
      $Grid->width = 80;
      $Grid->gap = 1;
      $Grid->arrange();

      yield assert(
         assertion: $A->width === 80 && $A->height === 9
            && $B->width === 59 && $B->height === 20
            && $C->width === 20 && $C->height === 20,
         description: 'The gap trims inner edges only — outer edges stay flush'
      );

      // @ Clamp — placements outside the tracks pin to the last cell
      $Grid->gap = 0;
      $Grid->arrange();

      $D = new Frame($Host);
      $Grid->place($D, row: 9, column: 9, rowspan: 9, colspan: 9);

      yield assert(
         assertion: $D->row === $C->row && $D->column === $C->column
            && $D->width === $C->width && $D->height === $C->height,
         description: 'Out-of-range placements clamp into the track grid'
      );

      // @ Cells — placements are exposed in paint order
      yield assert(
         assertion: count($Grid->Cells) === 4 && $Grid->Cells[0]->Box === $A
            && $Grid->Cells[3]->colspan === 9,
         description: 'The cells expose the placements in paint order'
      );

      // @ Negative weights — collapsed tracks, sums never exceed the space
      $Weird = new Grid($Host);
      $Weird->width = 80;
      $Weird->height = 30;
      $Weird->columns = [-1, 1, 1];

      $Collapsed = new Frame($Host);
      $Full = new Frame($Host);

      $Weird->place($Collapsed, row: 1, column: 1);
      $Weird->place($Full, row: 1, column: 1, colspan: 3);

      yield assert(
         assertion: $Collapsed->width === 0 && $Full->width === 80,
         description: 'Negative weights collapse to zero-width tracks keeping exact sums'
      );
   }
);
