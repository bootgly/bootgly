<?php

namespace Bootgly\CLI\UI\Components;


use const BOOTGLY_TTY;
use function assert;
use function rewind;
use function str_contains;
use function stream_get_contents;
use function substr_count;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should render the vertical timeline (append-only on pipes)',
   test: function () {
      // ! Timeline with an in-memory stream
      $Output = new Output('php://memory');

      $Timeline = new Timeline($Output);
      $Timeline->add('Mode');
      $Timeline->add('Path');

      $Timeline->start();
      $Timeline->advance('create');
      $Timeline->advance();

      rewind($Output->stream);
      $output = (string) stream_get_contents($Output->stream);

      // @ Valid
      yield assert(
         assertion: str_contains($output, 'Mode') === true && str_contains($output, 'Path') === true,
         description: 'Both steps render'
      );
      yield assert(
         assertion: str_contains($output, '✔') === true && str_contains($output, '(create)') === true,
         description: 'Done steps render the glyph and the note'
      );
      yield assert(
         assertion: $Timeline->finished === true,
         description: 'Advancing past the last step finishes the flow'
      );

      if (BOOTGLY_TTY === true) {
         yield assert(
            assertion: str_contains($output, '│') === true,
            description: 'Interactive frames connect steps with `│`'
         );
      }
      else {
         // ! Append-only: one `✔` line per completed step, no `│` connector, no repaints
         yield assert(
            assertion: str_contains($output, '│') === false && substr_count($output, '✔') === 2,
            description: 'Non-interactive output appends one plain line per transition'
         );
      }
   }
);
