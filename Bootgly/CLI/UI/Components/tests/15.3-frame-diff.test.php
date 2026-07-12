<?php

namespace Bootgly\CLI\UI\Components;


use const BOOTGLY_TTY;
use function assert;
use function preg_match;
use function rewind;
use function str_contains;
use function stream_get_contents;
use function strlen;
use function substr;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should diff-blit only the changed rectangle rows without erase escapes',
   test: function () {
      // ! Frame over an in-memory host stream
      $Host = new Output('php://memory');

      $read = static function () use ($Host): string {
         rewind($Host->stream);

         return (string) stream_get_contents($Host->stream);
      };

      $Frame = new Frame($Host);
      $Frame->width = 8;
      $Frame->height = 3;

      $Frame->Output->render("aa\n");
      $Frame->render();

      $written = $read();
      $painted = strlen($written);

      if (BOOTGLY_TTY === true) {
         // @ First blit — every rectangle row anchors at its absolute position
         yield assert(
            assertion: str_contains($written, "\e[1;1H") === true
               && str_contains($written, "\e[2;1H") === true
               && str_contains($written, "\e[3;1H") === true,
            description: 'The first render paints the full rectangle at its anchors'
         );

         // @ Unchanged content — a quiet frame writes zero bytes
         $Frame->render();

         yield assert(
            assertion: strlen($read()) === $painted,
            description: 'Unchanged frames emit zero bytes'
         );

         // @ One-row change — only the changed interior row repaints
         $Frame->Output->render("bb\n");
         $Frame->render();

         $delta = substr($read(), $painted);
         $painted = strlen($read());

         yield assert(
            assertion: str_contains($delta, "\e[2;1H") === true
               && str_contains($delta, 'bb') === true
               && str_contains($delta, "\e[1;1H") === false
               && str_contains($delta, "\e[3;1H") === false,
            description: 'A one-row change repaints exactly that rectangle row'
         );

         // @ Geometry change — the front buffer drops and the rectangle repaints
         $Frame->row = 5;
         $Frame->render();

         $delta = substr($read(), $painted);
         $painted = strlen($read());

         yield assert(
            assertion: str_contains($delta, "\e[5;1H") === true
               && str_contains($delta, "\e[6;1H") === true
               && str_contains($delta, "\e[7;1H") === true,
            description: 'Geometry changes force a full repaint at the new anchors'
         );

         // @ Invalidation — an external wipe demands a full repaint
         $Frame->invalidate();
         $Frame->render();

         $delta = substr($read(), $painted);

         yield assert(
            assertion: str_contains($delta, "\e[5;1H") === true
               && str_contains($delta, "\e[6;1H") === true
               && str_contains($delta, "\e[7;1H") === true,
            description: 'Invalidating repaints the full rectangle on the next render'
         );
      }
      else {
         // @ Non-interactive output — plain sequential rows, no cursor anchors
         yield assert(
            assertion: str_contains($written, '┌') === true
               && str_contains($written, "\n") === true
               && str_contains($written, "\e[1;1H") === false,
            description: 'Non-interactive frames write the rectangle rows plainly'
         );

         // @ Every render dumps the rectangle again (no diff on pipes)
         $Frame->render();

         yield assert(
            assertion: strlen($read()) > $painted,
            description: 'Non-interactive renders write sequentially without diffing'
         );
      }

      // @ Erase escapes never leave a frame — sibling frames stay intact
      yield assert(
         assertion: preg_match('/\x1B\[[0-9;]*[JK]/', $read()) === 0,
         description: 'No erase-in-line or erase-in-display escape is ever emitted'
      );
   }
);
