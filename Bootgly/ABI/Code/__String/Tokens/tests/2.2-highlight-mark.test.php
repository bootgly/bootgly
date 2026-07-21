<?php

namespace Bootgly\ABI\Code\__String\Tokens;


use function assert;
use function implode;
use function range;
use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should mark a line and window the excerpt around it',
   test: function () {
      // ! A 12-line source with distinct calls per line
      $lines = [];
      foreach (range(1, 12) as $index) {
         $lines[] = "call{$index}();";
      }
      $source = implode("\n", $lines);

      $Highlighter = new Highlighter;

      // @ Marked line 6 — default ±4 window
      $highlighted = $Highlighter->highlight($source, 6);

      yield assert(
         assertion: str_contains($highlighted, '▶') === true
            && str_contains($highlighted, "\e[91m  6") === true,
         description: 'The marked line carries the marker and a red number'
      );
      yield assert(
         assertion: str_contains($highlighted, 'call2') === true
            && str_contains($highlighted, 'call10') === true,
         description: 'The window spans 4 lines before and after the mark'
      );
      yield assert(
         assertion: str_contains($highlighted, 'call11') === false
            && str_contains($highlighted, '  1▕') === false,
         description: 'Lines outside the window are clipped'
      );

      // @ Custom window
      $highlighted = $Highlighter->highlight($source, 6, 1, 1);

      yield assert(
         assertion: str_contains($highlighted, 'call5') === true
            && str_contains($highlighted, 'call7') === true
            && str_contains($highlighted, 'call4') === false
            && str_contains($highlighted, 'call8') === false,
         description: 'lines_before/lines_after shrink the excerpt window'
      );
   }
);
