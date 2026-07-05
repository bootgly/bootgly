<?php

namespace Bootgly\CLI\UI\Components;


use const BOOTGLY_TTY;
use function assert;
use function fopen;
use function fwrite;
use function rewind;
use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should edit multiline text (Ctrl+D submits; stdin lines on pipes)',
   test: function () {
      // ! Control-level editing (pure key handling — no streams involved)
      $stream = fopen('php://memory', 'r+');
      $Input = new Input($stream); // @phpstan-ignore-line
      $Output = new Output('php://memory');

      $Textarea = new Textarea($Input, $Output);

      // @ Typing + Enter breaks the line
      $Textarea->control('a');
      $Textarea->control('b');
      $Textarea->control("\n");
      $Textarea->control('c');

      yield assert(
         assertion: $Textarea->lines === ['ab', 'c'] && $Textarea->row === 1 && $Textarea->column === 1,
         description: 'Enter splits the line at the cursor'
      );

      // @ Backspace at column 0 merges with the previous line
      $Textarea->control("\e[D"); // Left → column 0
      $Textarea->control("\x7F"); // Backspace

      yield assert(
         assertion: $Textarea->lines === ['abc'] && $Textarea->row === 0 && $Textarea->column === 2,
         description: 'Backspace at the line start merges with the previous line'
      );

      // @ Vertical moves clamp the column
      $Textarea->control("\n");
      $Textarea->control('x');
      $Textarea->control("\e[A"); // Up

      yield assert(
         assertion: $Textarea->row === 0,
         description: 'Up moves one line up'
      );

      // @ The frame renders the window + hint (protected render via bound Closure)
      $frame = (string) (fn (): null|string => $this->render(Textarea::RETURN_OUTPUT))->call($Textarea);

      yield assert(
         assertion: str_contains($frame, 'Ctrl+D') === true && str_contains($frame, 'xc') === true
            && str_contains($frame, "\e[7mb\e[0m") === true,
         description: 'The frame renders the visible lines, the inverse-video cursor cell and the Ctrl+D hint'
      );

      // ! ask() on pipes: stdin lines until EOF
      if (BOOTGLY_TTY === false) {
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, "line one\nline two\nline three\n");
         rewind($stream);

         $Input = new Input($stream); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         $Textarea = new Textarea($Input, $Output);

         yield assert(
            assertion: $Textarea->ask() === "line one\nline two\nline three",
            description: 'Non-interactive input joins the stdin lines with `\n`'
         );
      }
      else {
         // ! ask() on TTY: keys until Ctrl+D
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, "hi\nthere\x04"); // Ctrl+D submits
         rewind($stream);

         $Input = new Input($stream); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         $Textarea = new Textarea($Input, $Output);

         yield assert(
            assertion: $Textarea->ask() === "hi\nthere",
            description: 'Interactive editing submits on Ctrl+D'
         );
      }
   }
);
