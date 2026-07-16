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
   description: 'It should drive the interactive switching lifecycle per output mode',
   test: function () {
      $Host = new Output('php://memory');

      if (BOOTGLY_TTY === false) {
         // @ Non-interactive — a single render, then the generator closes
         $stream = fopen('php://memory', 'r+');
         $Input = new Input($stream); // @phpstan-ignore-line

         $Tabs = new Tabs($Input, $Host);
         $Tabs->width = 12;
         $Tabs->height = 4;
         $Tabs->add('Log');

         $yields = 0;
         foreach ($Tabs->switching() as $ignored) {
            $yields++;
         }

         rewind($Host->stream);
         $written = (string) stream_get_contents($Host->stream);

         yield assert(
            assertion: $yields === 1 && str_contains($written, '┌') === true,
            description: 'Non-interactive switching renders once and returns'
         );
      }
      else {
         // @ Interactive — keys cycle and jump; `q` ends and restores
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, "\t\e[Z3q");
         rewind($stream);
         $Input = new Input($stream); // @phpstan-ignore-line

         $Tabs = new Tabs($Input, $Host);
         $Tabs->width = 12;
         $Tabs->height = 4;
         $Tabs->add('Log');
         $Tabs->add('CPU');
         $Tabs->add('Table');

         $yields = [];
         foreach ($Tabs->switching() as $tab) {
            $yields[] = $tab;
         }

         // ! Pending keys drain in one tick, in order: "\t" cycles 1→2,
         //   "\e[Z" cycles 2→1, "3" jumps to 3, "q" ends the session
         yield assert(
            assertion: $yields === [1] && $Tabs->tab === 3,
            description: 'Pending keys drain in order within one paced tick'
         );

         rewind($Host->stream);
         $written = (string) stream_get_contents($Host->stream);

         yield assert(
            assertion: str_contains($written, "\e[?25l") === true
               && str_contains($written, "\e[?25h") === true,
            description: 'The interactive session hides the cursor and restores it'
         );
      }
   }
);
