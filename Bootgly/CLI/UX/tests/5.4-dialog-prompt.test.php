<?php

namespace Bootgly\CLI\UX;


use const BOOTGLY_TTY;
use function assert;
use function fopen;
use function fwrite;
use function rewind;
use function str_contains;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should prompt modally with the Line editor and acknowledge alerts',
   test: function () {
      $make = static function (string $bytes): Dialog {
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, $bytes);
         rewind($stream);

         $Input = new Input($stream); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         $Dialog = new Dialog($Input, $Output);
         $Dialog->centered = false;
         $Dialog->row = 1;
         $Dialog->column = 1;
         $Dialog->width = 40;
         $Dialog->height = 6;

         return $Dialog;
      };

      // @ Typed answer
      $Dialog = $make("App\n");
      $answer = $Dialog->prompt('Name', 'X');

      yield assert(
         assertion: $answer === 'App' && $Dialog->answer === 'App',
         description: 'A typed line submits on Enter'
      );

      rewind($Dialog->Output->stream);
      $output = (string) stream_get_contents($Dialog->Output->stream);

      yield assert(
         assertion: str_contains($output, 'Name') === true,
         description: 'The prompt reaches the output'
      );

      // @ Editing keys — Backspace erases before the cursor
      $Dialog = $make("Ab\x7Fpp\n");

      yield assert(
         assertion: $Dialog->prompt('Name') === 'App',
         description: 'Backspace edits the value before submitting'
      );

      // @ EOF keeps the default
      $Dialog = $make('');

      yield assert(
         assertion: $Dialog->prompt('Name', 'X') === 'X',
         description: 'EOF keeps the default'
      );

      if (BOOTGLY_TTY === true) {
         // @ Esc keeps the default (interactive only — pipes have no Esc)
         $Dialog = $make("\e");

         yield assert(
            assertion: $Dialog->prompt('Name', 'X') === 'X',
            description: 'A bare Esc keeps the default'
         );

         // @ An empty submit keeps the default
         $Dialog = $make("\n");

         yield assert(
            assertion: $Dialog->prompt('Name', 'X') === 'X',
            description: 'An empty Enter keeps the default'
         );

         // @ Arrow keys control the virtual cursor instead of feeding text
         $Dialog = $make("Ab\e[Dc\n");

         yield assert(
            assertion: $Dialog->prompt('Name') === 'Acb',
            description: 'A Left arrow moves the cursor before the insert'
         );

         // @ Multi-byte UTF-8 characters assemble whole before feeding
         $Dialog = $make("café\n");

         yield assert(
            assertion: $Dialog->prompt('Name') === 'café',
            description: 'Multi-byte UTF-8 input feeds complete characters'
         );

         // @ SS3 sequences (F1-F4) assemble whole — no final byte leaks as text
         $Dialog = $make("\eOPa\n");

         yield assert(
            assertion: $Dialog->prompt('Name') === 'a',
            description: 'An SS3 function key never leaks its final byte into the value'
         );
      }

      // @ Alert — acknowledged by any key (interactive) or immediate (pipes)
      $Dialog = $make(BOOTGLY_TTY === true ? ' ' : '');
      $Dialog->alert('Saved');

      rewind($Dialog->Output->stream);
      $output = (string) stream_get_contents($Dialog->Output->stream);

      yield assert(
         assertion: str_contains($output, 'Saved') === true,
         description: 'The alert message reaches the output'
      );

      yield assert(
         assertion: $Dialog->opened === false,
         description: 'Standalone alerts close the dialog on return'
      );
   }
);
