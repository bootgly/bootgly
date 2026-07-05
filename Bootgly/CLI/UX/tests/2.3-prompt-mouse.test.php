<?php

namespace Bootgly\CLI\UX;


use const BOOTGLY_TTY;
use function assert;
use function fopen;
use function fwrite;
use function rewind;
use function str_contains;
use function stream_get_contents;
use function substr_count;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should scroll the content band with the mouse wheel and drag the scrollbar',
   test: function () {
      if (BOOTGLY_TTY === true) {
         // ! Wheel up ×2 unsticks and scrolls the band; Ctrl+D ends
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, "\e[<64;5;5M\e[<64;5;5M\x04");
         rewind($stream);

         $Input = new Input($stream); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         $Prompt = new Prompt($Input, $Output);
         $Prompt->buffered = true;
         $Prompt->start();

         for ($index = 0; $index < 200; $index++) {
            $Prompt->feed("row {$index}");
         }

         foreach ($Prompt->prompting() as $line) {
            // ...no submits — wheel only, then Ctrl+D
         }

         $bottom = 200 - $Prompt->Scrollarea->rows;

         yield assert(
            assertion: $Prompt->Scrollarea->first === $bottom - 6
               && $Prompt->Scrollarea->stuck === false,
            description: 'Each wheel notch scrolls the band three rows up'
         );

         // @ Mouse reporting escapes are written on start and disabled on finish
         rewind($Output->stream);
         $written = (string) stream_get_contents($Output->stream);

         yield assert(
            assertion: str_contains($written, "\e[?1006h") === true
               && str_contains($written, "\e[?1003h") === true
               && str_contains($written, "\e[?1003l") === true
               && str_contains($written, "\e[?1006l") === true,
            description: 'SGR mouse reporting turns on at start and off at finish'
         );

         // ! Dragging: press the thumb, move to the band top, release — then Ctrl+D
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, "!\n\x04");
         rewind($stream);

         $Input = new Input($stream); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         $Prompt = new Prompt($Input, $Output);
         $Prompt->buffered = true;
         $Prompt->start();

         for ($index = 0; $index < 200; $index++) {
            $Prompt->feed("row {$index}");
         }

         // ? The drag bytes reference the real thumb position (stuck = band end)
         $column = $Prompt->Scrollarea->width;
         $end = $Prompt->Scrollarea->rows;

         $stream = fopen('php://memory', 'r+');
         fwrite($stream, "\e[<0;{$column};{$end}M\e[<32;{$column};1M\e[<0;{$column};1m\x04");
         rewind($stream);
         $Prompt->Input = new Input($stream); // @phpstan-ignore-line

         foreach ($Prompt->prompting() as $line) {
            // ...no submits — drag only, then Ctrl+D
         }

         yield assert(
            assertion: $Prompt->Scrollarea->first === 0 && $Prompt->Scrollarea->stuck === false,
            description: 'Pressing the thumb and dragging to the band top scrolls to the first rows'
         );

         // ! Ctrl+T toggles the selection mode: releases the mouse, then resumes it
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, "\x14a\x14\x04");
         rewind($stream);

         $Input = new Input($stream); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         $Prompt = new Prompt($Input, $Output);

         foreach ($Prompt->prompting() as $line) {
            // ...no submits — toggle, type, toggle, Ctrl+D
         }

         rewind($Output->stream);
         $written = (string) stream_get_contents($Output->stream);

         yield assert(
            assertion: substr_count($written, "\e[?1003h") === 2
               && substr_count($written, "\e[?1003l") === 2,
            description: 'Ctrl+T releases the reporting (typing never re-arms) and resumes it'
         );
         yield assert(
            assertion: str_contains($written, 'Selection mode') === true,
            description: 'The selection notice renders on the bottom border while released'
         );
      }
      else {
         // ! Pipes: mouse config exists; no reporting escapes leak
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, "first\n");
         rewind($stream);

         $Input = new Input($stream); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         $Prompt = new Prompt($Input, $Output);
         $Prompt->buffered = true;

         $lines = [];
         foreach ($Prompt->prompting() as $line) {
            $lines[] = $line;
         }

         rewind($Output->stream);
         $written = (string) stream_get_contents($Output->stream);

         // @ Valid
         yield assert(
            assertion: $Prompt->mouse === true && $lines === ['first'],
            description: 'The mouse support is enabled by default and pipes stay line-driven'
         );
         yield assert(
            assertion: str_contains($written, "\e[?100") === false,
            description: 'No mouse reporting escapes leak into pipes'
         );
      }
   }
);
