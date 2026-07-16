<?php

namespace Bootgly\CLI\UX\Components;


use function assert;
use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UX\Components\Toasts\Positions;


return new Specification(
   description: 'It should anchor every position with per-toast widths, gaps and slot clamping',
   test: function () {
      Terminal::$columns = 80;
      Terminal::$lines = 24;

      $Host = new Output('php://memory');

      // ! 'Hi' (2) → width 8; 'Longer' (6) → width 12; gap 1 → step 4
      $place = static function (Positions $Position) use ($Host): array {
         $Toasts = new Toasts($Host);
         $Toasts->Positions = $Position;
         $Toasts->gap = 1;

         $Toasts->add('Hi', TTL: 100.0, at: 0.0);
         $Toasts->add('Longer', TTL: 100.0, at: 0.0);
         $Toasts->render(Toasts::RETURN_OUTPUT, at: 1.0);

         $First = $Toasts->queue[0]['Frame'];
         $Second = $Toasts->queue[1]['Frame'];

         return [
            [$First->row, $First->column, $First->width],
            [$Second->row, $Second->column, $Second->width]
         ];
      };

      yield assert(
         assertion: $place(Positions::TopLeft) === [[1, 1, 8], [5, 1, 12]],
         description: 'TopLeft grows downward from row 1, column 1'
      );

      yield assert(
         assertion: $place(Positions::TopCenter) === [[1, 37, 8], [5, 35, 12]],
         description: 'TopCenter centers each box horizontally'
      );

      yield assert(
         assertion: $place(Positions::TopRight) === [[1, 73, 8], [5, 69, 12]],
         description: 'TopRight right-aligns each box to the screen edge'
      );

      // ! Center block: 2 slots × step 4 − gap 1 = 7 rows → starts at row 9
      yield assert(
         assertion: $place(Positions::Center) === [[9, 37, 8], [13, 35, 12]],
         description: 'Center centers the whole block vertically and each box horizontally'
      );

      yield assert(
         assertion: $place(Positions::BottomLeft) === [[22, 1, 8], [18, 1, 12]],
         description: 'BottomLeft grows upward from the last rows'
      );

      yield assert(
         assertion: $place(Positions::BottomCenter) === [[22, 37, 8], [18, 35, 12]],
         description: 'BottomCenter combines the upward growth with horizontal centering'
      );

      yield assert(
         assertion: $place(Positions::BottomRight) === [[22, 73, 8], [18, 69, 12]],
         description: 'BottomRight combines the upward growth with right alignment'
      );

      // @ Short terminals clamp the visible slots instead of overlapping
      Terminal::$lines = 5;

      $Toasts = new Toasts($Host);
      $Toasts->gap = 1;
      $Toasts->add('Hi', TTL: 100.0, at: 0.0);
      $Toasts->add('Longer', TTL: 100.0, at: 0.0);

      $returned = (string) $Toasts->render(Toasts::RETURN_OUTPUT, at: 1.0);

      yield assert(
         assertion: str_contains($returned, 'Longer') === true
            && str_contains($returned, 'Hi') === false,
         description: 'A short terminal shows only the newest toasts that fit'
      );

      // ! Restore the deterministic size for later tests
      Terminal::$lines = 24;
   }
);
