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
   description: 'It should prompt with a bottom-fixed input (region, history, multiline)',
   test: function () {
      if (BOOTGLY_TTY === true) {
         // ! Interactive: two submits, then history recall, then multiline, then Ctrl+D
         // keys: "one\n" · "two\n" · ↑ ↑ ↓ (recall walk) "\n" (submits `two`) ·
         //       "a" Alt+Enter "b" "\n" (multiline `a\nb`) · Ctrl+D
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, "one\ntwo\n\e[A\e[A\e[B\na\e\rb\n\x04");
         rewind($stream);

         $Input = new Input($stream); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         $Prompt = new Prompt($Input, $Output);
         $Prompt->buffered = true;
         $Prompt->top = ['left' => 'REPL', 'right' => 'v1'];
         $Prompt->bottom = ['left' => 'Ctrl+D quits', 'right' => ''];

         $lines = [];
         foreach ($Prompt->prompting() as $line) {
            $lines[] = $line;
         }

         // @ Valid
         yield assert(
            assertion: $lines === ['one', 'two', 'two', "a\nb"],
            description: 'Submits, history recall (↑↑↓ lands on `two`) and Alt+Enter multiline'
         );
         yield assert(
            assertion: $Prompt->entries === ['one', 'two', 'two', "a\nb"],
            description: 'Submitted lines enter the history ring'
         );

         rewind($Output->stream);
         $written = (string) stream_get_contents($Output->stream);

         yield assert(
            assertion: str_contains($written, 'r') === true && str_contains($written, "\e[r") === true,
            description: 'The scroll region is clipped on start and reset on finish'
         );
         yield assert(
            assertion: str_contains($written, '─') === true && str_contains($written, 'REPL') === true
               && str_contains($written, 'Ctrl+D quits') === true,
            description: 'The frame renders the borders and the fixed top/bottom texts'
         );
         yield assert(
            assertion: $Prompt->finished === true,
            description: 'Ctrl+D finishes the prompt'
         );
      }
      else {
         // ! Pipes: plain stdin line loop — identical consumer code
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, "first\nsecond\n");
         rewind($stream);

         $Input = new Input($stream); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         $Prompt = new Prompt($Input, $Output);
         $Prompt->buffered = true;

         $lines = [];
         foreach ($Prompt->prompting() as $line) {
            $Prompt->feed("echo: {$line}");
            $lines[] = $line;
         }

         rewind($Output->stream);
         $written = (string) stream_get_contents($Output->stream);

         // @ Valid
         yield assert(
            assertion: $lines === ['first', 'second'] && $Prompt->finished === true,
            description: 'Non-interactive input yields stdin lines until EOF'
         );
         yield assert(
            assertion: str_contains($written, 'echo: first') === true,
            description: 'feed() writes plainly on non-interactive output'
         );
         yield assert(
            assertion: str_contains($written, "\e[") === false,
            description: 'No scroll region escapes leak into pipes'
         );
      }
   }
);
