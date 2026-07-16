<?php

namespace Bootgly\CLI\UX\Components;


use const BOOTGLY_TTY;
use function assert;
use function fopen;
use function rewind;
use function str_contains;
use function stream_get_contents;
use function strlen;
use function substr;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Base\Frame;


return new Specification(
   description: 'It should wrap standalone sessions in the alternate screen buffer',
   test: function () {
      $stream = fopen('php://memory', 'r+');
      $Input = new Input($stream); // @phpstan-ignore-line
      $Host = new Output('php://memory');

      $read = static function () use ($Host): string {
         rewind($Host->stream);

         return (string) stream_get_contents($Host->stream);
      };

      // ! Background component — untouched by alternate screen restores
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
      $Dialog->screen = true;

      $Dialog->cover($Back);

      $before = strlen($read());
      $Dialog->open();
      $opening = substr($read(), $before);

      $before = strlen($read());
      $Dialog->close();
      $closing = substr($read(), $before);

      if (BOOTGLY_TTY === true) {
         yield assert(
            assertion: str_contains($opening, "\e[?1049h") === true,
            description: 'Opening enters the alternate screen buffer'
         );

         yield assert(
            assertion: str_contains($closing, "\e[?1049l") === true,
            description: 'Closing leaves the alternate screen buffer'
         );

         yield assert(
            assertion: str_contains($closing, "\e[1;1H") === false,
            description: 'The covered components stay untouched — the terminal restores the main buffer'
         );
      }
      else {
         yield assert(
            assertion: str_contains($opening, '1049') === false
               && str_contains($closing, '1049') === false,
            description: 'Non-interactive output never writes screen buffer escapes'
         );
      }
   }
);
