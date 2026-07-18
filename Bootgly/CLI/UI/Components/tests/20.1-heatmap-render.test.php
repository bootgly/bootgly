<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function getenv;
use function putenv;
use function str_contains;
use function substr_count;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should render a bordered dashboard card with state-colored cells',
   test: function () {
      // ! Deterministic truecolor escapes
      $previous = getenv('COLORTERM');
      putenv('COLORTERM=truecolor');

      try {
         $Output = new Output('php://memory');

         $Heatmap = new Heatmap($Output);
         $Heatmap->title = 'http';
         $Heatmap->width = 40;
         $Heatmap->cells = [
            'passed', 'passed', 'passed', 'passed', 'passed',
            'passed', 'passed', 'passed', 'passed',
            'failed', 'failed',
            'skipped',
         ];

         $frame = (string) $Heatmap->render(Heatmap::RETURN_OUTPUT);

         // @ Frame structure
         yield assert(
            assertion: str_contains($frame, '╭') && str_contains($frame, '╮')
               && str_contains($frame, '╰') && str_contains($frame, '╯'),
            description: 'The card draws rounded corners'
         );
         yield assert(
            assertion: str_contains($frame, 'http') && str_contains($frame, '75%'),
            description: 'The title row shows the title and the derived score'
         );
         yield assert(
            assertion: str_contains($frame, '9 / 12'),
            description: 'The counts row shows positives over total'
         );
         // @ Cells: grid (12) + Meter (inner width = 36)
         yield assert(
            assertion: substr_count($frame, '■') === 12 + 36,
            description: 'The grid renders one cell per entry and the Meter fills the inner width'
         );
         // @ Colors
         yield assert(
            assertion: str_contains($frame, "\e[38;2;224;103;159m")
               && str_contains($frame, "\e[38;2;224;108;117m")
               && str_contains($frame, "\e[38;2;216;208;187m"),
            description: 'Cells sample the palette colors (passed, failed, skipped)'
         );
         // @ Explicit score
         $Heatmap->score = 33.0;
         $frame = (string) $Heatmap->render(Heatmap::RETURN_OUTPUT);
         yield assert(
            assertion: str_contains($frame, '33%'),
            description: 'An explicit score overrides the derived one'
         );
      }
      finally {
         putenv($previous === false ? 'COLORTERM' : "COLORTERM={$previous}");
      }
   }
);
