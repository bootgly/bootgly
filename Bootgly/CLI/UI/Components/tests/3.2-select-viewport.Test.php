<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function fopen;
use function fwrite;
use function rewind;
use function str_contains;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Test(
   description: 'It should window long option lists with `↑/↓ N more` indicators',
   test: function () {
      // ! Select with in-memory streams (Enter finishes the interactive loop)
      $stream = fopen('php://memory', 'r+');
      fwrite($stream, "\n");
      rewind($stream);

      $Input = new Input($stream); // @phpstan-ignore-line
      $Output = new Output('php://memory');

      $Select = new Select($Input, $Output);
      $Select->title = 'Pick an item';
      $Select->viewport = 3;

      for ($index = 0; $index < 10; $index++) {
         $Select->options[] = "Item {$index}";
      }

      // @ Aim down to the 5th option
      $Select->control("\e[B");
      $Select->control("\e[B");
      $Select->control("\e[B");
      $Select->control("\e[B");

      // @@ Render (interactive: consumes the Enter; non-interactive: renders once)
      foreach ($Select->selecting() as $ignored);

      // @ Valid — the window slid to keep the aimed option visible
      yield assert(
         assertion: $Select->Listbox->Window->first === 2 && $Select->Listbox->Window->last === 4,
         description: 'The window slides to keep the aimed option visible ([2..4] aims 4)'
      );

      rewind($Output->stream);
      $output = (string) stream_get_contents($Output->stream);

      // @ Valid
      yield assert(
         assertion: str_contains($output, 'Item 4') === true && str_contains($output, 'Item 2') === true,
         description: 'Windowed options render'
      );
      yield assert(
         assertion: str_contains($output, 'Item 0 ') === false && str_contains($output, 'Item 9') === false,
         description: 'Options outside the window are hidden'
      );
      yield assert(
         assertion: str_contains($output, '↑ 2 more') === true && str_contains($output, '↓ 5 more') === true,
         description: 'The `↑/↓ N more` indicators count the hidden options'
      );
   }
);
