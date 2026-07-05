<?php

namespace Bootgly\CLI\UI\Components;


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
   description: 'It should window long option lists with `↑/↓ N more` indicators',
   test: function () {
      // ! Menu with in-memory streams (Enter finishes the interactive loop)
      $stream = fopen('php://memory', 'r+');
      fwrite($stream, "\n");
      rewind($stream);

      $Input = new Input($stream); // @phpstan-ignore-line
      $Output = new Output('php://memory');

      $Menu = new Menu($Input, $Output);
      $Menu->prompt = 'Pick an item';

      $Options = $Menu->Items->Options;
      $Options->Selection::Unique->set();
      $Options->viewport = 3;

      for ($index = 0; $index < 10; $index++) {
         $Options->add(label: "Item {$index}");
      }

      // @ Aim down to the 5th option — the window slides to keep it visible
      $Options->control("\e[B");
      $Options->control("\e[B");
      $Options->control("\e[B");
      $Options->control("\e[B");

      // @ Valid
      yield assert(
         assertion: $Options->Window->first === 2 && $Options->Window->last === 4,
         description: 'The window slides to keep the aimed option visible ([2..4] aims 4)'
      );

      // @@ Render (interactive: consumes the Enter; non-interactive: renders once)
      foreach ($Menu->rendering() as $ignored);

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
