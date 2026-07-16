<?php

namespace Bootgly\CLI\UX\Components;


use const BOOTGLY_TTY;
use function assert;
use function fopen;
use function rewind;
use function str_contains;
use function str_repeat;
use function stream_get_contents;
use function strlen;
use function substr;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should paint the box at its rectangle, diff-blit and blank on close',
   test: function () {
      $stream = fopen('php://memory', 'r+');
      $Input = new Input($stream); // @phpstan-ignore-line
      $Host = new Output('php://memory');

      $read = static function () use ($Host): string {
         rewind($Host->stream);

         return (string) stream_get_contents($Host->stream);
      };

      $Dialog = new Dialog($Input, $Host);
      $Dialog->centered = false;
      $Dialog->row = 2;
      $Dialog->column = 3;
      $Dialog->width = 20;
      $Dialog->height = 5;
      $Dialog->Frame->title = 'Modal';

      $Dialog->Frame->Output->write("Body\n");

      $Dialog->open();

      yield assert(
         assertion: $Dialog->opened === true,
         description: 'Opening flags the dialog as opened'
      );

      $painted = $read();

      yield assert(
         assertion: str_contains($painted, '╭') === true
            && str_contains($painted, 'Body') === true,
         description: 'The box paints the Round border and the body content'
      );

      if (BOOTGLY_TTY === true) {
         yield assert(
            assertion: str_contains($painted, "\e[2;3H") === true,
            description: 'Interactive rows anchor at the rectangle coordinates'
         );

         // ? Unchanged content repaints nothing
         $Dialog->render();

         yield assert(
            assertion: strlen($read()) === strlen($painted),
            description: 'A second render with unchanged content emits zero bytes'
         );

         // @ Invalidating forces the next render to repaint the full rectangle
         $Dialog->invalidate();
         $Dialog->render();

         yield assert(
            assertion: strlen($read()) > strlen($painted),
            description: 'Invalidating repaints the full rectangle'
         );
      }
      else {
         yield assert(
            assertion: str_contains($painted, "\e[2;3H") === false,
            description: 'Non-interactive output writes plainly without cursor anchors'
         );
      }

      // @ The inherited $render property pins RETURN mode (Tabs precedent)
      $Dialog->render = Dialog::RETURN_OUTPUT;
      $returned = $Dialog->render();
      $Dialog->render = Dialog::WRITE_OUTPUT;

      yield assert(
         assertion: $returned !== null && str_contains((string) $returned, '╭') === true,
         description: 'The render property returns the rectangle instead of writing'
      );

      // @ Closing blanks the rectangle (no erase escapes)
      $before = strlen($read());
      $Dialog->close();
      $delta = substr($read(), $before);

      yield assert(
         assertion: $Dialog->opened === false,
         description: 'Closing flags the dialog as closed'
      );

      if (BOOTGLY_TTY === true) {
         yield assert(
            assertion: str_contains($delta, "\e[2;3H" . str_repeat(' ', 20)) === true
               && str_contains($delta, "\e[6;3H" . str_repeat(' ', 20)) === true,
            description: 'Closing blanks every rectangle row with literal spaces'
         );
      }
      else {
         yield assert(
            assertion: $delta === '',
            description: 'Non-interactive closes write nothing'
         );
      }

      // @ Auto-centering — deterministic through the resize handler
      $Centered = new Dialog($Input, $Host);
      $Centered->width = 10;
      $Centered->height = 5;
      $Centered->resize(80, 24);

      yield assert(
         assertion: $Centered->row === 10 && $Centered->column === 36,
         description: 'Resizing centers the rectangle against the terminal size'
      );
   }
);
