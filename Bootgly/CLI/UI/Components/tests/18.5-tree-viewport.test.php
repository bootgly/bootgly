<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function fopen;
use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should window long trees with `↑/↓ N more` indicators',
   test: function () {
      // ! Tree with in-memory streams
      $Input = new Input(fopen('php://memory', 'r+')); // @phpstan-ignore-line
      $Output = new Output('php://memory');

      $Tree = new Tree($Input, $Output);
      $Tree->viewport = 3;

      for ($index = 0; $index < 10; $index++) {
         $Tree->add("Item {$index}");
      }

      // @ Aim down to the 5th row — the window slides to keep it visible
      $Tree->control("\e[B");
      $Tree->control("\e[B");
      $Tree->control("\e[B");
      $Tree->control("\e[B");

      // @ Valid
      yield assert(
         assertion: $Tree->Window->first === 2 && $Tree->Window->last === 4,
         description: 'The window slides to keep the aimed row visible ([2..4] aims 4)'
      );

      $frame = (string) $Tree->render(Tree::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($frame, "Item 4\n") === true && str_contains($frame, "Item 2\n") === true,
         description: 'Windowed rows render'
      );
      yield assert(
         assertion: str_contains($frame, "Item 0\n") === false && str_contains($frame, "Item 9\n") === false,
         description: 'Rows outside the window are hidden'
      );
      yield assert(
         assertion: str_contains($frame, '↑ 2 more') === true && str_contains($frame, '↓ 5 more') === true,
         description: 'The `↑/↓ N more` indicators count the hidden rows'
      );

      // @ Null viewport renders everything
      $Tree->viewport = null;
      $full = (string) $Tree->render(Tree::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($full, "Item 0\n") === true && str_contains($full, "Item 9\n") === true
            && str_contains($full, 'more') === false,
         description: 'A null viewport renders every row without indicators'
      );
   }
);
