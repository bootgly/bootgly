<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function getenv;
use function putenv;
use function rewind;
use function str_contains;
use function stream_get_contents;
use function substr_count;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should stream the grid live and degrade plainly without a TTY',
   test: function () {
      // ! Deterministic truecolor escapes
      $previous = getenv('COLORTERM');
      putenv('COLORTERM=truecolor');

      try {
         // # Plain (no TTY): feeds stay silent, finish renders the single frame
         $Output = new Output('php://memory');
         $Heatmap = new Heatmap($Output);
         $Heatmap->decoration = false;
         $Heatmap->width = 40;
         $Heatmap->start();
         $Heatmap->feed('passed', 'passed');
         $Heatmap->feed('failed');
         $Heatmap->finish();

         rewind($Output->stream);
         $plain = (string) stream_get_contents($Output->stream);

         yield assert(
            assertion: substr_count($plain, '■') === 3 && substr_count($plain, "\n") === 1,
            description: 'Plain output renders a single final frame with every fed cell'
         );
         yield assert(
            assertion: str_contains($plain, "\e[?25l") === false,
            description: 'Plain output never hides the cursor'
         );

         // # Live (decoration forced): start paints, feeds repaint, finish restores
         $Output = new Output('php://memory');
         $Heatmap = new Heatmap($Output);
         $Heatmap->decoration = true;
         $Heatmap->throttle = 0.0;
         $Heatmap->width = 40;
         $Heatmap->heading = 'Assertions';
         $Heatmap->start();
         $Heatmap->feed('passed');
         $Heatmap->feed('failed');
         $Heatmap->caption = '1 / 2 assertions';
         $Heatmap->finish();

         rewind($Output->stream);
         $live = (string) stream_get_contents($Output->stream);

         yield assert(
            assertion: substr_count($live, 'Assertions') === 4,
            description: 'The live grid repaints on start, each feed and finish'
         );
         yield assert(
            assertion: substr_count($live, '■') === 5,
            description: 'Each repaint redraws every fed cell'
         );
         yield assert(
            assertion: str_contains($live, "\e[?25l") && str_contains($live, "\e[?25h"),
            description: 'The live grid hides the cursor while streaming and restores it'
         );
         yield assert(
            assertion: str_contains($live, '1 / 2 assertions'),
            description: 'Labels updated mid-stream land on the final frame'
         );
      }
      finally {
         putenv($previous === false ? 'COLORTERM' : "COLORTERM={$previous}");
      }
   }
);
