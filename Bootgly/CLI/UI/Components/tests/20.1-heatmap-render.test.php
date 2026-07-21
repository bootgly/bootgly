<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function count;
use function explode;
use function getenv;
use function mb_strlen;
use function preg_replace;
use function putenv;
use function rtrim;
use function str_contains;
use function substr_count;

use Bootgly\ABI\Code\__String;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should render a wrapped grid of state-colored cells with corner labels',
   test: function () {
      // ! Deterministic truecolor escapes
      $previous = getenv('COLORTERM');
      putenv('COLORTERM=truecolor');

      try {
         $Output = new Output('php://memory');

         $Heatmap = new Heatmap($Output);
         $Heatmap->width = 40;
         $Heatmap->cells = [
            'passed', 'passed', 'passed', 'passed', 'passed',
            'passed', 'passed', 'passed', 'passed',
            'failed', 'failed',
            'skipped',
         ];

         $frame = (string) $Heatmap->render(Heatmap::RETURN_OUTPUT);

         // @ Grid
         yield assert(
            assertion: substr_count($frame, '■') === 12,
            description: 'The grid renders one cell per entry'
         );
         yield assert(
            assertion: substr_count($frame, "\n") === 1,
            description: 'Cells within the width span a single row'
         );
         // @ Colors
         yield assert(
            assertion: str_contains($frame, "\e[38;2;152;195;121m")
               && str_contains($frame, "\e[38;2;224;108;117m")
               && str_contains($frame, "\e[38;2;216;208;187m"),
            description: 'Cells sample the palette colors (passed, failed, skipped)'
         );

         // @ Wrap — each cell spans 2 columns, so width 10 fits 5 per row
         $Heatmap->width = 10;
         $frame = (string) $Heatmap->render(Heatmap::RETURN_OUTPUT);
         yield assert(
            assertion: substr_count($frame, "\n") === 3,
            description: 'The grid wraps the cells by the width'
         );

         // @ Corner labels — heading/summary above, caption/note below
         $Heatmap->width = 40;
         $Heatmap->heading = 'Assertions';
         $Heatmap->summary = '@:error:2 failed@;';
         $Heatmap->caption = '9 / 12 assertions';
         $Heatmap->note = 'suite 4';
         $frame = (string) $Heatmap->render(Heatmap::RETURN_OUTPUT);

         yield assert(
            assertion: str_contains($frame, 'Assertions')
               && str_contains($frame, '2 failed')
               && str_contains($frame, '9 / 12 assertions')
               && str_contains($frame, 'suite 4'),
            description: 'The corner labels land around the grid'
         );

         $rows = explode("\n", rtrim($frame, "\n"));
         $visible = mb_strlen(
            (string) preg_replace(__String::ANSI_ESCAPE_SEQUENCE_REGEX, '', $rows[0] ?? '')
         );
         yield assert(
            assertion: count($rows) === 3 && $visible === 40,
            description: 'Label rows frame the grid and align flush with the width'
         );

         // @ Unknown states render dim
         $Heatmap->heading = '';
         $Heatmap->summary = '';
         $Heatmap->caption = '';
         $Heatmap->note = '';
         $Heatmap->cells = ['unknown'];
         $frame = (string) $Heatmap->render(Heatmap::RETURN_OUTPUT);
         yield assert(
            assertion: str_contains($frame, "\e[90m"),
            description: 'A state missing from the palette renders dim'
         );
      }
      finally {
         putenv($previous === false ? 'COLORTERM' : "COLORTERM={$previous}");
      }
   }
);
