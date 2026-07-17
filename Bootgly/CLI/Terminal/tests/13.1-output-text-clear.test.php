<?php

namespace Bootgly\CLI\Terminal;


use function assert;
use function rewind;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should clear the display unbounded or exactly N rows (bounded)',
   test: function () {
      $capture = static function (callable $write): string {
         $Output = new Output('php://memory');

         $write($Output);

         rewind($Output->stream);

         return (string) stream_get_contents($Output->stream);
      };

      // @ Unbounded — down clears to the end of the screen (ED 0J)
      yield assert(
         assertion: $capture(
            static fn (Output $Output) => $Output->Text->clear(down: true)
         ) === "\e[0J",
         description: 'clear(down) erases to the end of the screen'
      );

      // @ Bounded — exactly N rows, cursor back at the starting row
      yield assert(
         assertion: $capture(
            static fn (Output $Output) => $Output->Text->clear(lines: 1)
         ) === "\e[2K",
         description: 'clear(lines: 1) erases the cursor row only'
      );

      yield assert(
         assertion: $capture(
            static fn (Output $Output) => $Output->Text->clear(lines: 3)
         ) === "\e[2K\e[1B\e[2K\e[1B\e[2K\e[2A",
         description: 'clear(lines: 3) erases three rows and returns to the starting row — content below survives'
      );
   }
);
