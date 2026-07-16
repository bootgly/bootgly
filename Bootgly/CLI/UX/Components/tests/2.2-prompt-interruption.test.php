<?php

namespace Bootgly\CLI\UX\Components;


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
   description: 'It should warn on the first Ctrl+C and end on a second within the timeout',
   test: function () {
      if (BOOTGLY_TTY === true) {
         // ! Interactive: submit, Ctrl+C (notice), type (dismiss), submit, Ctrl+C ×2 (end)
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, "hi\n\x03a\n\x03\x03");
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
            assertion: $lines === ['hi', 'a'],
            description: 'A lone Ctrl+C never ends the loop — typing dismisses the notice'
         );
         yield assert(
            assertion: str_contains($written, 'Press Ctrl+C again to exit') === true,
            description: 'The first Ctrl+C renders the interruption notice on the bottom border'
         );
         yield assert(
            assertion: str_contains($written, ">_ \e[0m\e[7m \e[0m") === true,
            description: 'The painted input row keeps the prefix space and the inverse-video cursor block'
         );
         yield assert(
            assertion: $Prompt->finished === true,
            description: 'A second Ctrl+C within the timeout finishes the prompt'
         );

         // ! PgUp scrolls the content band up and unsticks it
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, "\e[5~\x04");
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
            // ...no submits — PgUp then Ctrl+D
         }

         yield assert(
            assertion: $Prompt->Scrollarea->stuck === false
               && $Prompt->Scrollarea->first < 200 - $Prompt->Scrollarea->rows,
            description: 'PgUp scrolls the content band up (the position unsticks)'
         );

         // ! Submitting sticks the band back to the bottom
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, "ok\n\x04");
         rewind($stream);

         $Input = new Input($stream); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         $Prompt = new Prompt($Input, $Output);
         $Prompt->buffered = true;
         $Prompt->start();

         for ($index = 0; $index < 200; $index++) {
            $Prompt->feed("row {$index}");
         }
         $Prompt->Scrollarea->scroll(-100);

         $submitted = [];
         foreach ($Prompt->prompting() as $line) {
            $submitted[] = $line;
         }

         yield assert(
            assertion: $submitted === ['ok'] && $Prompt->Scrollarea->stuck === true,
            description: 'Submitting a line sticks the content band back to the bottom'
         );
      }
      else {
         // ! Pipes: no notice by default — the frame returns without it
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, "first\n");
         rewind($stream);

         $Input = new Input($stream); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         $Prompt = new Prompt($Input, $Output);
         $Prompt->buffered = true;
         $frame = (string) $Prompt->render(Prompt::RETURN_OUTPUT);

         // @ Valid
         yield assert(
            assertion: str_contains($frame, '>_') === true
               && str_contains($frame, 'Press Ctrl+C again to exit') === false,
            description: 'The frame renders without the interruption notice by default'
         );
         yield assert(
            assertion: $Prompt->interruption === 'Press Ctrl+C again to exit',
            description: 'The interruption notice text is configurable (English default)'
         );
      }
   }
);
