<?php

namespace Bootgly\CLI\UI\Atoms;


use function assert;
use function rewind;
use function str_contains;
use function str_ends_with;
use function stream_get_contents;
use function substr_count;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;


return new Test(
   description: 'It should measure the thumb and render the strip: styled, hovered, plain',
   test: function () {
      $Output = new Output('php://memory');
      $Scrollbar = new Scrollbar($Output);
      $Scrollbar->decoration = true;

      // @ check() — the bar slides only when the content overflows the view
      $Scrollbar->height = 4;
      $Scrollbar->total = 4;

      yield assert(
         assertion: $Scrollbar->check() === false
            && ($Scrollbar->total = 16) === 16
            && $Scrollbar->check() === true,
         description: 'The bar slides only when the total overflows the height'
      );

      // @ measure() — the Scrollarea case: 4 rows over 16, stuck at the bottom
      $Scrollbar->first = 12;

      yield assert(
         assertion: $Scrollbar->measure() === [3, 1],
         description: 'Four rows over sixteen at the bottom put a one-cell thumb on the last row'
      );

      // @ measure() — the Listbox case: 3 rows over 6, aimed at the top
      $Scrollbar->height = 3;
      $Scrollbar->total = 6;
      $Scrollbar->first = 0;

      yield assert(
         assertion: $Scrollbar->measure() === [0, 2],
         description: 'Three rows over six at the top grow a two-cell thumb from the first row'
      );

      // @ measure() — the drag invariant: stuck at the bottom, the thumb
      //   touches the last strip row (the Prompt band drag presses there)
      $Scrollbar->height = 4;
      $Scrollbar->total = 200;
      $Scrollbar->first = 196;
      [$start, $size] = $Scrollbar->measure();

      yield assert(
         assertion: $start + $size === $Scrollbar->height,
         description: 'Stuck at the bottom the thumb always touches the last strip row'
      );

      // @ measure() — a non-sliding bar has no thumb
      $Scrollbar->total = 4;

      yield assert(
         assertion: $Scrollbar->measure() === [0, 0]
            && $Scrollbar->render(Component::RETURN_OUTPUT) === '',
         description: 'A non-sliding bar measures nothing and renders nothing'
      );

      // @ RETURN — glyph rows joined by newlines, no trailing one
      $Scrollbar->height = 4;
      $Scrollbar->total = 16;
      $Scrollbar->first = 12;
      $strip = (string) $Scrollbar->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: substr_count($strip, "\e[90m█\e[0m") === 1
            && substr_count($strip, "\e[90m│\e[0m") === 3
            && substr_count($strip, "\n") === 3
            && str_ends_with($strip, "\n") === false,
         description: 'The strip returns one painted glyph row per view row'
      );

      // @ Hovered — the thumb takes the accent, the track keeps the style
      $Scrollbar->hover(true);
      $strip = (string) $Scrollbar->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: substr_count($strip, "\e[97m█\e[0m") === 1
            && substr_count($strip, "\e[90m│\e[0m") === 3,
         description: 'A hovered thumb accents while the track keeps its paint'
      );

      // @ Plain — zero escapes, glyphs only
      $Scrollbar->decoration = false;
      $strip = (string) $Scrollbar->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($strip, "\e") === false
            && substr_count($strip, '█') === 1
            && substr_count($strip, '│') === 3,
         description: 'Plain decoration strips every escape from the strip'
      );

      // @ WRITE placed — each row painted in place at the strip column
      $Placed = new Scrollbar($Output);
      $Placed->decoration = true;
      $Placed->row = 10;
      $Placed->column = 20;
      $Placed->height = 4;
      $Placed->total = 16;
      $Placed->first = 12;
      $Placed->render();

      rewind($Output->stream);
      $written = (string) stream_get_contents($Output->stream);

      yield assert(
         assertion: str_contains($written, "\e[10;20H") === true
            && str_contains($written, "\e[13;20H") === true
            && str_contains($written, "\e[90m█\e[0m") === true,
         description: 'A placed strip repaints row by row at its column'
      );

      // @ WRITE unplaced — rows written in flow, one per line
      $Memory = new Output('php://memory');
      $Flow = new Scrollbar($Memory);
      $Flow->decoration = false;
      $Flow->height = 3;
      $Flow->total = 6;
      $Flow->render();

      rewind($Memory->stream);
      $written = (string) stream_get_contents($Memory->stream);

      yield assert(
         assertion: substr_count($written, "\n") === 3
            && str_ends_with($written, "\n") === true
            && str_contains($written, '█') === true,
         description: 'An unplaced WRITE_OUTPUT writes the glyph rows in flow'
      );
   }
);
