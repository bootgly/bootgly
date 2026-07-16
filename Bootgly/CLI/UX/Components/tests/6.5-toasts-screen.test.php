<?php

namespace Bootgly\CLI\UX\Components;


use const BOOTGLY_TTY;
use function assert;
use function count;
use function rewind;
use function str_contains;
use function str_repeat;
use function stream_get_contents;
use function strlen;
use function substr;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Base\Frame;


return new Specification(
   description: 'It should clear, invalidate and re-anchor on resize',
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
      $Back->column = 61;
      $Back->width = 20;
      $Back->height = 5;
      $Back->Output->write("App\n");
      $Back->render();

      $Toasts = new Toasts($Host);
      $Toasts->cover($Back);

      // ! 'Hello' (5) → width 11; TopRight → column 70
      //   (huge TTL: resize() ticks on the real clock)
      $Toasts->add('Hello', TTL: 1.0e12, at: 0.0);
      $Toasts->render(at: 1.0);

      // @ clear() dismisses everything and restores the covered components
      $painted = strlen($read());
      $Toasts->clear();
      $delta = substr($read(), $painted);

      yield assert(
         assertion: count($Toasts->queue) === 0,
         description: 'Clearing empties the queue'
      );

      if (BOOTGLY_TTY === true) {
         yield assert(
            assertion: str_contains($delta, "\e[1;70H" . str_repeat(' ', 11)) === true
               && str_contains($delta, "\e[1;61H") === true,
            description: 'Clearing blanks the stack and repaints the covered components'
         );
      }
      else {
         yield assert(
            assertion: $delta === '',
            description: 'Non-interactive clears write nothing'
         );
      }

      // @ invalidate() forces a full repaint on the next render
      $Toasts->add('Hello', TTL: 1.0e12, at: 1.0);
      $Toasts->render(at: 1.5);

      $painted = strlen($read());
      $Toasts->invalidate();
      $Toasts->render(at: 2.0);

      if (BOOTGLY_TTY === true) {
         yield assert(
            assertion: strlen($read()) > $painted,
            description: 'Invalidating repaints the full boxes'
         );
      }

      // @ resize() re-anchors the corner to the new terminal size
      $Toasts->resize(100, 30);

      if (BOOTGLY_TTY === true) {
         yield assert(
            assertion: $Toasts->queue[0]['Frame']->column === 90
               && str_contains($read(), "\e[1;90H") === true,
            description: 'Resizing re-anchors the right corner to the new width'
         );
      }
      else {
         // ? Non-interactive resizes only store the size — next anchors use it
         $Toasts->render(Toasts::RETURN_OUTPUT, at: 2.5);

         yield assert(
            assertion: $Toasts->queue[0]['Frame']->column === 90,
            description: 'Non-interactive resizes store the size for the next anchor'
         );
      }
   }
);
