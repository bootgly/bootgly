<?php

namespace Bootgly\CLI\UX;


use const BOOTGLY_TTY;
use function assert;
use function rewind;
use function str_contains;
use function str_repeat;
use function stream_get_contents;
use function strlen;
use function substr;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Frame;
use Bootgly\CLI\UX\Toasts\Positions;


return new Specification(
   description: 'It should blank vacated cells and repaint the covered components on reflow',
   test: function () {
      Terminal::$columns = 80;
      Terminal::$lines = 24;

      $Host = new Output('php://memory');

      $read = static function () use ($Host): string {
         rewind($Host->stream);

         return (string) stream_get_contents($Host->stream);
      };

      // ! Background component under the stack corner
      $Back = new Frame($Host);
      $Back->row = 1;
      $Back->column = 1;
      $Back->width = 20;
      $Back->height = 10;
      $Back->Output->write("App\n");
      $Back->render();

      $Toasts = new Toasts($Host);
      $Toasts->Positions = Positions::TopLeft;

      $Toasts->cover($Back);

      yield assert(
         assertion: $Toasts->Covered === [$Back],
         description: 'Covering registers the component'
      );

      // @ Three toasts — the middle one dies first
      $Toasts->add('Alpha', TTL: 100.0, at: 0.0);
      $Toasts->add('Bravo', TTL: 1.0, at: 0.0);
      $Toasts->add('Charlie', TTL: 100.0, at: 0.0);

      $Toasts->render(at: 0.5);

      yield assert(
         assertion: $Toasts->queue[0]['Frame']->row === 1
            && $Toasts->queue[1]['Frame']->row === 4
            && $Toasts->queue[2]['Frame']->row === 7,
         description: 'The stack grows away from the corner, oldest flush at it'
      );

      $painted = strlen($read());

      // @ The middle toast expires — the survivor recompacts toward the corner
      $Toasts->render(at: 2.0);

      $delta = substr($read(), $painted);

      yield assert(
         assertion: $Toasts->queue[1]['message'] === 'Charlie'
            && $Toasts->queue[1]['Frame']->row === 4,
         description: 'The survivor re-anchors into the vacated slot'
      );

      if (BOOTGLY_TTY === true) {
         // ! 'Bravo' (5) → width 11 at old row 4; 'Charlie' (7) → width 13 at old row 7
         yield assert(
            assertion: str_contains($delta, "\e[4;1H" . str_repeat(' ', 11)) === true
               && str_contains($delta, "\e[7;1H" . str_repeat(' ', 13)) === true,
            description: 'The stale rects blank with literal spaces'
         );

         yield assert(
            assertion: str_contains($delta, "\e[1;1H") === true
               && str_contains($delta, "\e[10;1H") === true,
            description: 'The covered component repaints its full rectangle once'
         );

         yield assert(
            assertion: str_contains($delta, 'Charlie') === true,
            description: 'The moved box repaints at its new slot'
         );
      }
      else {
         yield assert(
            assertion: $delta === '',
            description: 'Non-interactive reflows write nothing'
         );
      }

      // @ Eviction at the limit also reflows
      $painted = strlen($read());

      $Toasts->add('Delta', TTL: 100.0, at: 2.0);
      $Toasts->add('Echo', TTL: 100.0, at: 2.0);
      $Toasts->render(at: 2.5);

      yield assert(
         assertion: $Toasts->queue[1]['Frame']->row === 1
            && $Toasts->queue[3]['Frame']->row === 7,
         description: 'Eviction at the limit shifts the visible window'
      );

      if (BOOTGLY_TTY === true) {
         yield assert(
            assertion: strlen($read()) > $painted,
            description: 'The eviction reflow repaints the stack'
         );
      }
   }
);
