<?php

namespace Bootgly\CLI\Terminal;


use function assert;
use function rewind;
use function str_contains;
use function stream_get_contents;
use function strlen;

use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should write the alternate screen buffer escape sequences when opening and closing',
   test: function () {
      // ! Screen with an in-memory Output
      $Output = new Output('php://memory');
      $Screen = new Screen($Output);

      // @ Open
      $Screen->open();
      rewind($Output->stream);
      $written = (string) stream_get_contents($Output->stream);

      // @ Valid
      yield assert(
         assertion: str_contains($written, "\e[?1049h"),
         description: 'Opening enters the alternate screen buffer (?1049h)'
      );
      yield assert(
         assertion: str_contains($written, "\e[2J") && str_contains($written, "\e[H"),
         description: 'Opening clears the buffer and homes the cursor (2J + H)'
      );
      yield assert(
         assertion: $Screen->alternative === true,
         description: 'Opening flags the alternate buffer state'
      );

      // @ Open again (idempotent)
      $size = strlen($written);
      $Screen->open();
      rewind($Output->stream);
      $written = (string) stream_get_contents($Output->stream);

      yield assert(
         assertion: strlen($written) === $size,
         description: 'Reopening an open Screen writes nothing'
      );

      // @ Close
      $Screen->close();
      rewind($Output->stream);
      $written = (string) stream_get_contents($Output->stream);

      yield assert(
         assertion: str_contains($written, "\e[?1049l"),
         description: 'Closing restores the main screen buffer (?1049l)'
      );
      yield assert(
         assertion: $Screen->alternative === false,
         description: 'Closing unflags the alternate buffer state'
      );

      // @ Close again (idempotent)
      $size = strlen($written);
      $Screen->close();
      rewind($Output->stream);
      $written = (string) stream_get_contents($Output->stream);

      yield assert(
         assertion: strlen($written) === $size,
         description: 'Reclosing a closed Screen writes nothing'
      );
   }
);
