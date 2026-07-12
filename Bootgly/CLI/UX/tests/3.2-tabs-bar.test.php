<?php

namespace Bootgly\CLI\UX;


use function assert;
use function count;
use function explode;
use function fopen;
use function mb_strlen;
use function preg_match;
use function preg_replace;
use function str_contains;
use function trim;

use Bootgly\ABI\Data\__String;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Frame\Borders;


return new Specification(
   description: 'It should ride the labels bar on the active frame top border',
   test: function () {
      // ! Visible width helper — painted bytes measured without escapes
      $measure = static function (string $painted): int {
         return mb_strlen(
            (string) preg_replace(__String::ANSI_ESCAPE_SEQUENCE_REGEX, '', $painted)
         );
      };

      $stream = fopen('php://memory', 'r+');
      $Input = new Input($stream); // @phpstan-ignore-line
      $Host = new Output('php://memory');

      $Tabs = new Tabs($Input, $Host);
      $Tabs->width = 40;
      $Tabs->height = 6;

      $Log = $Tabs->add('Log');
      $CPU = $Tabs->add('CPU');
      $Tabs->add('Table');

      // @ Bar — active label highlighted, divisors from the active border set
      $frame = (string) $Tabs->render(Tabs::RETURN_OUTPUT);
      $rows = explode("\n", trim($frame, "\n"));

      yield assert(
         assertion: str_contains($rows[0], "\e[7;1m Log \e[0m") === true
            && str_contains($rows[0], ' CPU ') === true
            && str_contains($rows[0], ' Table ') === true
            && str_contains($rows[0], '│') === true
            && str_contains($rows[0], '┌') === true,
         description: 'The top border row embeds the bar with the active label inverted'
      );
      yield assert(
         assertion: $measure($rows[0]) === 40 && count($rows) === 6,
         description: 'The bar row keeps the exact rectangle width'
      );

      // @ Switching moves the highlight and re-derives the divisor
      $CPU->Borders = Borders::Double;
      $Tabs->switch('CPU');

      $frame = (string) $Tabs->render(Tabs::RETURN_OUTPUT);
      $rows = explode("\n", trim($frame, "\n"));

      yield assert(
         assertion: str_contains($rows[0], "\e[7;1m CPU \e[0m") === true
            && str_contains($rows[0], "\e[7;1m Log \e[0m") === false
            && str_contains($rows[0], '║') === true
            && str_contains($rows[0], '╔') === true,
         description: 'The bar follows the active frame — highlight and border set included'
      );

      // @ Truncation — narrow rectangles crop the strip, never overflow
      $Tabs->width = 16;
      $frame = (string) $Tabs->render(Tabs::RETURN_OUTPUT);
      $rows = explode("\n", trim($frame, "\n"));

      yield assert(
         assertion: $measure($rows[0]) === 16
            && preg_match('/\x1B\[[0-9;]*[JK]/', $frame) === 0,
         description: 'Narrow bars crop to the exact width without erase escapes'
      );

      // @ Borderless active frame — no border, no bar (documented caveat)
      $Tabs->width = 40;
      $Tabs->switch('Log');
      $Log->Borders = Borders::None;
      $Log->Output->render("plain\n");

      $frame = (string) $Tabs->render(Tabs::RETURN_OUTPUT);
      $rows = explode("\n", trim($frame, "\n"));

      yield assert(
         assertion: count($rows) === 6 && str_contains($frame, '┌') === false
            && str_contains($frame, "\e[7;1m Log \e[0m") === false,
         description: 'Borderless active frames hide the bar with the border'
      );

      // @ Control characters in labels never break the rectangle (titles are single-line)
      $Wild = new Tabs($Input, $Host);
      $Wild->width = 40;
      $Wild->height = 6;
      $Wild->add("Line1\nLine2");
      $Wild->add('Bad@.;Label');

      $frame = (string) $Wild->render(Tabs::RETURN_OUTPUT);
      $rows = explode("\n", trim($frame, "\n"));

      yield assert(
         assertion: count($rows) === 6 && $measure($rows[0]) === 40
            && str_contains($rows[0], 'Line1Line2') === true,
         description: 'Newlines in labels are stripped — the bar row never splits'
      );
   }
);
