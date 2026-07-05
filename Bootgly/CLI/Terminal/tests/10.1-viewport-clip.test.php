<?php

namespace Bootgly\CLI\Terminal;


use function assert;
use function rewind;
use function str_contains;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should emit the DECSTBM scroll region escapes (set and reset)',
   test: function () {
      // ! Output with an in-memory stream
      $Output = new Output('php://memory');

      // @ Clip a region
      $Output->Viewport->clip(1, 20);

      // @ Reset (no arguments)
      $Output->Viewport->clip();

      rewind($Output->stream);
      $written = (string) stream_get_contents($Output->stream);

      // @ Valid
      yield assert(
         assertion: str_contains($written, "\e[1;20r") === true,
         description: 'clip(top, bottom) emits `CSI top;bottom r` (DECSTBM)'
      );
      yield assert(
         assertion: str_contains($written, "\e[r") === true,
         description: 'clip() with no arguments emits the bare `CSI r` reset'
      );
   }
);
