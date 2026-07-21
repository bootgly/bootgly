<?php

namespace Bootgly\CLI\UI\Base;


use function assert;
use function explode;
use function mb_strlen;
use function preg_replace;
use function rewind;
use function rtrim;
use function str_contains;
use function stream_get_contents;

use Bootgly\ABI\Code\__String;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should box markup content with borders, title and separators',
   test: function () {
      // ! Visible width helper — painted bytes measured without escapes
      $measure = static function (string $painted): int {
         return mb_strlen(
            (string) preg_replace(__String::ANSI_ESCAPE_SEQUENCE_REGEX, '', $painted)
         );
      };

      $Output = new Output('php://memory');

      // @ Frame structure — title embedded in the top border
      $Fieldset = new Fieldset($Output);
      $Fieldset->title = 'Usage';
      $Fieldset->content = "@#Cyan:first@; line\n@---;\nsecond";

      $frame = (string) $Fieldset->render(Fieldset::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($frame, '┌') && str_contains($frame, '┐')
            && str_contains($frame, '└') && str_contains($frame, '┘'),
         description: 'The box draws the default corners'
      );
      yield assert(
         assertion: str_contains($frame, ' Usage '),
         description: 'The title interrupts the top border'
      );
      yield assert(
         assertion: str_contains($frame, "\e[96m"),
         description: 'Content markup resolves to raw escapes'
      );

      // @ Separator — a `@---;` line renders as a mid-border row
      $rows = explode("\n", rtrim($frame, "\n"));
      yield assert(
         assertion: str_contains($rows[2] ?? '', '─') && str_contains($rows[2] ?? '', '│'),
         description: 'A @---; content line renders as a separator row'
      );

      // @ Geometry — every row spans the same visible columns
      $width = $measure($rows[0]);
      $aligned = true;
      foreach ($rows as $row) {
         if ($measure($row) !== $width) {
            $aligned = false;

            break;
         }
      }
      yield assert(
         assertion: $aligned && $width === ($Fieldset->width ?? 0) + 4,
         description: 'Rows align to the derived inner width plus borders/padding'
      );

      // @ Fixed width — the inner width is respected, not derived
      $Fixed = new Fieldset($Output);
      $Fixed->width = 30;
      $Fixed->content = 'short';
      $frame = (string) $Fixed->render(Fieldset::RETURN_OUTPUT);
      $rows = explode("\n", rtrim($frame, "\n"));
      yield assert(
         assertion: $measure($rows[1] ?? '') === 34,
         description: 'A fixed width pads content rows to the inner columns'
      );

      // @ Write mode — the frame goes to the Output stream
      $Fixed->render();
      rewind($Output->stream);
      $written = (string) stream_get_contents($Output->stream);
      yield assert(
         assertion: str_contains($written, '┌') && str_contains($written, 'short'),
         description: 'Write mode flushes the frame to the Output'
      );
   }
);
