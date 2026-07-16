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
   description: 'It should revert to the previous field on `↑` + Enter (interactive only)',
   test: function () {
      if (BOOTGLY_TTY === true) {
         // ! Interactive: answer A, revert from B, redo A (previous answer as default), then B
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, "First\n\e[A\nRedone\nSecond\n\n");
         rewind($stream);

         $Input = new Input($stream); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         $Form = new Form($Input, $Output);
         $Form->add('A');
         $Form->add('B');

         // @
         $answers = $Form->ask();

         // @ Valid
         yield assert(
            assertion: $answers === ['A' => 'Redone', 'B' => 'Second'],
            description: '`↑` + Enter steps back one field; the redone answer wins'
         );

         rewind($Output->stream);
         $output = (string) stream_get_contents($Output->stream);

         yield assert(
            assertion: str_contains($output, '[First]') === true,
            description: 'The reverted field re-asks with the previous answer as default'
         );
      }
      else {
         // ! Non-interactive: strictly sequential — no revert
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, "First\nSecond\n");
         rewind($stream);

         $Input = new Input($stream); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         $Form = new Form($Input, $Output);
         $Form->add('A');
         $Form->add('B');

         // @
         $answers = $Form->ask();

         // @ Valid
         yield assert(
            assertion: $answers === ['A' => 'First', 'B' => 'Second'] && $Form->confirmed === true,
            description: 'Non-interactive streams read one line per field, in order'
         );
      }
   }
);
