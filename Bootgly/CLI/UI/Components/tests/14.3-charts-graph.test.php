<?php

namespace Bootgly\CLI\UI\Components;


use const BOOTGLY_TTY;
use function assert;
use function count;
use function explode;
use function rewind;
use function str_contains;
use function stream_get_contents;
use function trim;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Chart\Gradient;
use Bootgly\CLI\UI\Components\Chart\Symbols;
use Bootgly\CLI\UI\Components\Charts\Graph;


return new Specification(
   description: 'It should stream values into a multi-row symbol graph on both stream modes',
   test: function () {
      // @ Symbol maps — 25 cells, full pair at the end, inversion flips the fill direction
      yield assert(
         assertion: count(Symbols::Braille->map()) === 25
            && Symbols::Braille->map()[24] === '⣿'
            && Symbols::Braille->map(inverted: true)[12] === '⠛'
            && Symbols::TTY->map()[24] === '█'
            && Symbols::Block->map()[24] === '█',
         description: 'Symbol maps expose 5x5 cells per set and direction'
      );

      // ! Graph with an in-memory stream
      $Output = new Output('php://memory');

      $Graph = new Graph($Output);
      $Graph->width = 10;
      $Graph->height = 2;
      $Graph->ceiling = 100.0;
      $Graph->Gradient = new Gradient(['#000000', '#ffffff'], extended: false);

      // @ Static render — series without feeding
      $Graph->series = ['a' => 100.0, 'b' => 100.0];
      $frame = (string) $Graph->render(Graph::RETURN_OUTPUT);
      $lines = explode("\n", trim($frame, "\n"));

      yield assert(
         assertion: count($lines) === 2 && str_contains($lines[0], '⣿') === true,
         description: 'Static series render height rows; full values fill the top row cell'
      );

      // @ Feed — history slides and caps to the capacity
      $Graph->capacity = 4;
      for ($value = 0; $value < 10; $value++) {
         $Graph->feed((float) $value);
      }

      yield assert(
         assertion: count($Graph->values) === 4 && $Graph->values[0] === 6.0,
         description: 'The history caps to the capacity keeping the most recent values'
      );

      // @ Dual-mode — live on interactive terminals, single final frame on pipes
      $Stream = new Output('php://memory');
      $Live = new Graph($Stream);
      $Live->width = 5;
      $Live->height = 2;
      $Live->ceiling = 100.0;
      $Live->throttle = 0.0;
      $Live->Gradient = new Gradient(['#ffffff'], extended: false);

      $Live->start();
      $Live->feed(50.0);
      $Live->feed(100.0);
      $Live->finish();

      rewind($Stream->stream);
      $written = (string) stream_get_contents($Stream->stream);

      if (BOOTGLY_TTY === true) {
         yield assert(
            assertion: str_contains($written, "\e[?25l") === true
               && str_contains($written, "\e[2F") === true
               && str_contains($written, "\e[?25h") === true,
            description: 'Interactive graphs hide the cursor, repaint relatively and restore on finish'
         );
      }
      else {
         $frame = trim($written, "\n");

         yield assert(
            assertion: count(explode("\n", $frame)) === 2
               && str_contains($written, "\e[?25l") === false
               && str_contains($written, "\e[2F") === false,
            description: 'Non-interactive graphs write the final frame only — no cursor escapes'
         );
      }

      // @ Inverted — down-graphs fill top-down with the mirrored symbol map
      $Down = new Graph($Output);
      $Down->width = 2;
      $Down->height = 1;
      $Down->ceiling = 100.0;
      $Down->inverted = true;
      $Down->Gradient = new Gradient(['#ffffff'], extended: false);
      $Down->series = ['a' => 50.0, 'b' => 50.0];

      $frame = (string) $Down->render(Graph::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($frame, '⠛') === true,
         description: 'Inverted graphs plot with the top-down symbol map'
      );
   }
);
