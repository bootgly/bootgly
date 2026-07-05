<?php

namespace Bootgly\CLI\UI\Components;


use const PHP_EOL;
use function assert;
use function explode;
use function fopen;
use function fwrite;
use function in_array;
use function rewind;
use function str_contains;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should lay options out in vertical grid columns with row-wise aiming',
   test: function () {
      // ! Menu with in-memory streams (Enter finishes the interactive loop)
      $stream = fopen('php://memory', 'r+');
      fwrite($stream, "\n");
      rewind($stream);

      $Input = new Input($stream); // @phpstan-ignore-line
      $Output = new Output('php://memory');

      $Menu = new Menu($Input, $Output);
      $Menu->prompt = 'Pick a cell';

      $Options = $Menu->Items->Options;
      $Options->Selection::Unique->set();
      $Options->columns = 3;

      for ($index = 0; $index < 6; $index++) {
         $Options->add(label: "A{$index}");
      }

      // @ ↓ moves one visual line (+3); → moves one cell (+1); ↑ moves one line (-3)
      $Options->control("\e[B");
      $Options->control("\e[C");
      $Options->control("\e[A");
      $Options->control(PHP_EOL);

      // @ Valid — 0 → 3 → 4 → 1
      yield assert(
         assertion: in_array(1, $Options::$selected[0]) === true,
         description: 'Arrows traverse the grid row-wise (↑/↓ = ±columns, ←/→ = ±1)'
      );

      // @@ Render the grid frame
      foreach ($Menu->rendering() as $ignored);

      rewind($Output->stream);
      $output = (string) stream_get_contents($Output->stream);

      // ! First grid row: A0..A2 on one line, A3 on the next
      $row = '';
      foreach (explode("\n", $output) as $line) {
         if (str_contains($line, 'A0') === true) {
            $row = $line;
            break;
         }
      }

      // @ Valid
      yield assert(
         assertion: $row !== '' && str_contains($row, 'A1') === true && str_contains($row, 'A2') === true,
         description: 'Grid rows join N options per visual line'
      );
      yield assert(
         assertion: str_contains($row, 'A3') === false,
         description: 'Line breaks split the grid every N options'
      );
   }
);
