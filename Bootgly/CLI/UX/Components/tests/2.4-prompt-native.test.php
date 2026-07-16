<?php

namespace Bootgly\CLI\UX\Components;


use const BOOTGLY_TTY;
use function assert;
use function fopen;
use function fwrite;
use function preg_match;
use function rewind;
use function str_contains;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should flow content natively (buffered = false) — no region, no mouse reporting',
   test: function () {
      if (BOOTGLY_TTY === true) {
         // ! Native flow opt-in: feeds join the flow, the frame stays bottom-fixed
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, "hello\n\x04");
         rewind($stream);

         $Input = new Input($stream); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         $Prompt = new Prompt($Input, $Output);
         $Prompt->buffered = false;
         $Prompt->start();

         $Prompt->feed('fed content line');

         $lines = [];
         foreach ($Prompt->prompting() as $line) {
            $lines[] = $line;
         }

         rewind($Output->stream);
         $written = (string) stream_get_contents($Output->stream);

         // @ Valid
         yield assert(
            assertion: $lines === ['hello'],
            description: 'The native flow yields submitted lines'
         );
         yield assert(
            assertion: str_contains($written, 'fed content line') === true
               && str_contains($written, '─') === true,
            description: 'Fed content joins the flow and the frame borders render below it'
         );
         yield assert(
            assertion: preg_match('/\e\[\d+;1H/', $written) === 1,
            description: 'The frame repaints at absolute rows (bottom-fixed)'
         );
         yield assert(
            assertion: preg_match('/\e\[\d+;\d+r/', $written) === 0
               && str_contains($written, "\e[?1003h") === false,
            description: 'No scroll region and no mouse reporting — selection stays native'
         );
      }
      else {
         // ! Pipes: identical plain behavior
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, "first\n");
         rewind($stream);

         $Input = new Input($stream); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         $Prompt = new Prompt($Input, $Output);

         // @ Valid
         yield assert(
            assertion: $Prompt->buffered === true,
            description: 'The buffered band (internal scrollbar) is the default mode'
         );

         $Prompt->buffered = false;
         $Prompt->feed('plain feed');

         $lines = [];
         foreach ($Prompt->prompting() as $line) {
            $lines[] = $line;
         }

         rewind($Output->stream);
         $written = (string) stream_get_contents($Output->stream);

         yield assert(
            assertion: $lines === ['first'] && str_contains($written, 'plain feed') === true,
            description: 'The native flow behaves identically on pipes (plain writes)'
         );
      }
   }
);
