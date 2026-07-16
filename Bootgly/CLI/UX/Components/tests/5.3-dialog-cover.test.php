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
use Bootgly\CLI\UI\Base\Frame;


return new Specification(
   description: 'It should repaint the covered components when it closes',
   test: function () {
      $stream = fopen('php://memory', 'r+');
      $Input = new Input($stream); // @phpstan-ignore-line
      $Host = new Output('php://memory');

      $read = static function () use ($Host): string {
         rewind($Host->stream);

         return (string) stream_get_contents($Host->stream);
      };

      // ! Background component under the modal
      $Back = new Frame($Host);
      $Back->row = 1;
      $Back->column = 1;
      $Back->width = 12;
      $Back->height = 4;
      $Back->Output->write("App\n");
      $Back->render();

      $Dialog = new Dialog($Input, $Host);
      $Dialog->centered = false;
      $Dialog->row = 2;
      $Dialog->column = 2;
      $Dialog->width = 8;
      $Dialog->height = 3;

      $Dialog->cover($Back);

      yield assert(
         assertion: $Dialog->Covered === [$Back],
         description: 'Covering registers the component'
      );

      $Dialog->Frame->Output->write("Hi\n");
      $Dialog->open();

      $painted = strlen($read());

      $Dialog->close();

      $delta = substr($read(), $painted);

      if (BOOTGLY_TTY === true) {
         yield assert(
            assertion: str_contains($delta, "\e[2;2H" . str_repeat(' ', 8)) === true,
            description: 'Closing blanks the modal rectangle first'
         );

         yield assert(
            assertion: str_contains($delta, "\e[1;1H") === true
               && str_contains($delta, "\e[2;1H") === true
               && str_contains($delta, "\e[3;1H") === true
               && str_contains($delta, "\e[4;1H") === true,
            description: 'The covered component repaints its full rectangle over the blank'
         );
      }
      else {
         yield assert(
            assertion: $delta === '',
            description: 'Non-interactive closes write nothing'
         );
      }
   }
);
