<?php

namespace Bootgly\CLI\UI\Atoms;


use function assert;
use function implode;
use function range;
use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should window the output around the marked line',
   test: function () {
      // ! A 12-line source with distinct calls per line
      $lines = [];
      foreach (range(1, 12) as $index) {
         $lines[] = "call{$index}();";
      }
      $source = implode("\n", $lines);

      // @ Default window (4 before, 4 after) around line 6 — plain for byte asserts
      $Highlighter = new Highlighter(new Output('php://memory'));
      $Highlighter->decoration = false;
      $Highlighter->mark = 6;
      $Highlighter->source = $source;
      $rendered = (string) $Highlighter->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, '▶') === true,
         description: 'The marked line carries the marker glyph'
      );
      yield assert(
         assertion: str_contains($rendered, 'call2();') === true
            && str_contains($rendered, 'call10();') === true,
         description: 'The window spans 4 lines before and after the mark'
      );
      yield assert(
         assertion: str_contains($rendered, 'call1();') === false
            && str_contains($rendered, 'call11();') === false,
         description: 'Lines outside the window are clipped'
      );

      // @ Custom window
      $Highlighter = new Highlighter(new Output('php://memory'));
      $Highlighter->decoration = false;
      $Highlighter->mark = 6;
      $Highlighter->before = 1;
      $Highlighter->after = 1;
      $Highlighter->source = $source;
      $rendered = (string) $Highlighter->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, 'call5();') === true
            && str_contains($rendered, 'call7();') === true
            && str_contains($rendered, 'call4();') === false
            && str_contains($rendered, 'call8();') === false,
         description: 'before/after shrink the excerpt window'
      );

      // @ Decorated mark — blinking marker and red line number
      $Highlighter = new Highlighter(new Output('php://memory'));
      $Highlighter->decoration = true;
      $Highlighter->mark = 3;
      $Highlighter->before = 1;
      $Highlighter->after = 1;
      $Highlighter->source = $source;
      $rendered = (string) $Highlighter->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, "\e[5;31m") === true
            && str_contains($rendered, "\e[91m  3") === true,
         description: 'Decorated output paints the marker and the marked number'
      );
   }
);
