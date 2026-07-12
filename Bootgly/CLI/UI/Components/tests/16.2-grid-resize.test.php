<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function preg_match;
use function rewind;
use function stream_get_contents;
use function strlen;
use function substr_count;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should reflow every placed frame through shrink and grow resizes',
   test: function () {
      // ! Grid over an in-memory host stream — uniform 2×2 layout
      $Host = new Output('php://memory');

      $read = static function () use ($Host): string {
         rewind($Host->stream);

         return (string) stream_get_contents($Host->stream);
      };

      $Grid = new Grid($Host);
      $Grid->width = 60;
      $Grid->height = 20;
      $Grid->rows = [1, 1];
      $Grid->columns = [1, 1];

      $A = new Frame($Host);
      $B = new Frame($Host);
      $C = new Frame($Host);
      $D = new Frame($Host);

      $Grid
         ->place($A, row: 1, column: 1)
         ->place($B, row: 1, column: 2)
         ->place($C, row: 2, column: 1)
         ->place($D, row: 2, column: 2);

      yield assert(
         assertion: $A->width === 30 && $A->height === 10 && $B->column === 31 && $C->row === 11,
         description: 'Uniform tracks split the rectangle evenly'
      );

      // @ Grow — every placed frame reflows and the screen clears
      $Grid->resize(100, 40);

      yield assert(
         assertion: $Grid->width === 100 && $Grid->height === 40
            && $A->width === 50 && $A->height === 20
            && $B->column === 51 && $C->row === 21
            && $A->width + $B->width === 100,
         description: 'Growing recomputes every placed frame geometry'
      );
      yield assert(
         assertion: preg_match('/\x1B\[[0-9;]*J/', $read()) === 1,
         description: 'Resizing clears the screen to wipe stale rows'
      );

      $painted = strlen($read());

      // @ Shrink — odd spaces keep exact sums after the reflow
      $Grid->resize(57, 21);

      yield assert(
         assertion: $A->width + $B->width === 57 && $B->column === $A->width + 1
            && $A->height === 11 && $C->row === 12 && $C->height === 10,
         description: 'Shrinking to odd spaces reflows with exact track sums'
      );
      yield assert(
         assertion: strlen($read()) > $painted,
         description: 'Resizing repaints the layout after the reflow'
      );

      // @ RETURN mode — the layout returns the frame rectangles concatenated
      $returned = (string) $Grid->render(Grid::RETURN_OUTPUT);

      yield assert(
         assertion: substr_count($returned, '┌') === 4 && substr_count($returned, '┘') === 4,
         description: 'Returning renders concatenates every placed frame rectangle'
      );

      // @ Terminal-sized grid — null width/height track the terminal metrics
      $Auto = new Grid($Host);

      $Whole = new Frame($Host);
      $Auto->place($Whole, row: 1, column: 1);

      yield assert(
         assertion: $Whole->width === (int) Terminal::$columns
            && $Whole->height === (int) Terminal::$lines,
         description: 'Null grid geometry tracks the terminal size at arrange time'
      );
   }
);
