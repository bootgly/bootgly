<?php

namespace Bootgly\CLI\UI\Base;


use function assert;
use function count;
use function explode;
use function mb_strlen;
use function preg_replace;
use function str_contains;
use function trim;

use Bootgly\ABI\Data\__String;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Chart\Gradient;
use Bootgly\CLI\UI\Components\Charts\Sparkline;
use Bootgly\CLI\UI\Base\Frame\Borders;


return new Specification(
   description: 'It should compose the exact bordered rectangle with clip, wrap and view modes',
   test: function () {
      // ! Visible width helper — painted bytes measured without escapes
      $measure = static function (string $painted): int {
         return mb_strlen(
            (string) preg_replace(__String::ANSI_ESCAPE_SEQUENCE_REGEX, '', $painted)
         );
      };

      $Host = new Output('php://memory');

      // @ Geometry — height rows of exactly width visible columns, title embedded
      $Frame = new Frame($Host);
      $Frame->width = 10;
      $Frame->height = 4;
      $Frame->title = 'CPU';

      $Frame->Output->render("content\n");

      $frame = (string) $Frame->render(Frame::RETURN_OUTPUT);
      $rows = explode("\n", trim($frame, "\n"));

      $exact = true;
      foreach ($rows as $row) {
         if ($measure($row) !== 10) {
            $exact = false;
         }
      }

      yield assert(
         assertion: count($rows) === 4 && $exact === true,
         description: 'The rectangle composes height rows of exactly width visible columns'
      );
      yield assert(
         assertion: str_contains($rows[0], '┌') === true
            && str_contains($rows[0], ' CPU ') === true
            && str_contains($rows[0], '┐') === true
            && str_contains($rows[3], '└') === true
            && str_contains($rows[3], '┘') === true,
         description: 'Sharp borders draw the box corners and the top row embeds the title'
      );
      yield assert(
         assertion: $Frame->columns === 8 && $Frame->lines === 2,
         description: 'The inner geometry derives from the outer rectangle minus the borders'
      );

      // @ Border sets — Round glyphs and the borderless full rectangle
      $Frame->Borders = Borders::Round;
      $frame = (string) $Frame->render(Frame::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($frame, '╭') === true && str_contains($frame, '╯') === true,
         description: 'Border sets swap the box glyphs'
      );

      $Frame->Borders = Borders::None;
      $frame = (string) $Frame->render(Frame::RETURN_OUTPUT);
      $rows = explode("\n", trim($frame, "\n"));

      yield assert(
         assertion: count($rows) === 4 && $measure($rows[0]) === 10
            && str_contains($frame, '┌') === false
            && $Frame->columns === 10 && $Frame->lines === 4,
         description: 'Borderless frames span the interior over the full rectangle'
      );

      // @ View — tail follows the newest lines, head holds the first lines
      $View = new Frame($Host);
      $View->width = 10;
      $View->height = 3;

      $View->Output->render("one\ntwo\nthree\n");
      $frame = (string) $View->render(Frame::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($frame, 'three') === true && str_contains($frame, 'one') === false,
         description: 'Following frames view the buffer tail (the newest lines win)'
      );

      $View->follow = false;
      $frame = (string) $View->render(Frame::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($frame, 'one') === true && str_contains($frame, 'three') === false,
         description: 'Unfollowed frames view the buffer head'
      );

      // @ Clip vs wrap — iframe semantics by default, opt-in row wrapping
      $Cut = new Frame($Host);
      $Cut->width = 6;
      $Cut->height = 4;

      $Cut->Output->write("\e[31mabcdefgh\n");
      $frame = (string) $Cut->render(Frame::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($frame, "\e[31mabcd\e[0m") === true
            && str_contains($frame, 'efgh') === false,
         description: 'Clipping discards the overflow and closes the open SGR at the cut'
      );

      $Wrap = new Frame($Host);
      $Wrap->width = 6;
      $Wrap->height = 4;
      $Wrap->wrap = true;

      $Wrap->Output->write("abcdefgh\n");
      $frame = (string) $Wrap->render(Frame::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($frame, 'abcd') === true && str_contains($frame, 'efgh') === true,
         description: 'Wrapping breaks long lines into extra interior rows'
      );

      // @ Degenerate sizes — border only, never negative paddings
      $Tiny = new Frame($Host);
      $Tiny->width = 2;
      $Tiny->height = 2;

      $Tiny->Output->render("invisible\n");
      $frame = (string) $Tiny->render(Frame::RETURN_OUTPUT);
      $rows = explode("\n", trim($frame, "\n"));

      yield assert(
         assertion: count($rows) === 2 && $measure($rows[0]) === 2 && $measure($rows[1]) === 2
            && str_contains($frame, 'invisible') === false,
         description: 'Degenerate rectangles compose the border only'
      );

      // @ Hosted component — a Chart bound to the isolated Output just works
      $Chart = new Frame($Host);
      $Chart->width = 12;
      $Chart->height = 3;

      $Sparkline = new Sparkline($Chart->Output);
      $Sparkline->series = [1.0, 5.0, 8.0];
      $Sparkline->Gradient = new Gradient(['#ffffff'], extended: false);
      $Sparkline->render();

      $frame = (string) $Chart->render(Frame::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($frame, '█') === true,
         description: 'Components render inside the frame through its isolated Output'
      );

      // @ Compound styles — every active SGR carries into the wrapped row
      $Styled = new Frame($Host);
      $Styled->width = 6;
      $Styled->height = 4;
      $Styled->wrap = true;

      $Styled->Output->write("\e[1m\e[31mabcdefgh\n");
      $frame = (string) $Styled->render(Frame::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($frame, "abcd\e[0m") === true
            && str_contains($frame, "\e[1m\e[31mefgh") === true,
         description: 'Wrapped rows close and reopen every accumulated SGR'
      );

      // @ Wrap overflow — several wrapped logical lines fill the interior per view
      $Over = new Frame($Host);
      $Over->width = 6;
      $Over->height = 4;
      $Over->wrap = true;

      $Over->Output->render("abcdefgh\nijklmnop\n");
      $frame = (string) $Over->render(Frame::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($frame, 'ijkl') === true && str_contains($frame, 'mnop') === true
            && str_contains($frame, 'abcd') === false,
         description: 'Following frames tail the last wrapped visual rows'
      );

      $Over->follow = false;
      $frame = (string) $Over->render(Frame::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($frame, 'abcd') === true && str_contains($frame, 'efgh') === true
            && str_contains($frame, 'mnop') === false,
         description: 'Unfollowed frames head the first wrapped visual rows'
      );

      // @ Degenerate widths — bordered rows never exceed the declared width
      $Sliver = new Frame($Host);
      $Sliver->width = 1;
      $Sliver->height = 3;

      $Sliver->Output->render("hidden\n");
      $frame = (string) $Sliver->render(Frame::RETURN_OUTPUT);
      $rows = explode("\n", trim($frame, "\n"));

      $exact = true;
      foreach ($rows as $row) {
         if ($measure($row) !== 1) {
            $exact = false;
         }
      }

      yield assert(
         assertion: count($rows) === 3 && $exact === true,
         description: 'A width-1 bordered frame paints exactly one visible column per row'
      );

      $Sliver->width = 0;
      $frame = (string) $Sliver->render(Frame::RETURN_OUTPUT);
      $rows = explode("\n", $frame);

      yield assert(
         assertion: $rows[0] === '' && $rows[1] === '' && $rows[2] === '',
         description: 'A width-0 frame paints nothing on every row'
      );

      $Sliver->width = 10;
      $Sliver->height = 1;
      $frame = (string) $Sliver->render(Frame::RETURN_OUTPUT);
      $rows = explode("\n", trim($frame, "\n"));

      yield assert(
         assertion: count($rows) === 1 && $measure($rows[0]) === 10,
         description: 'A height-1 bordered frame paints the top border row only'
      );

      // @ Border maps — every set exposes its glyphs; None exposes nothing
      yield assert(
         assertion: Borders::Double->map()['top-left'] === '╔'
            && Borders::Heavy->map()['top-left'] === '┏'
            && Borders::Sharp->map()['bottom-right'] === '┘'
            && Borders::Round->map()['top-left'] === '╭'
            && Borders::None->map() === [],
         description: 'Border sets map their position glyphs'
      );

      // @ Title — escapes in the title are sanitized (never an erase into the border)
      $Titled = new Frame($Host);
      $Titled->width = 12;
      $Titled->height = 3;
      $Titled->title = "T\e[K\e[2J";

      $frame = (string) $Titled->render(Frame::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($frame, ' T ') === true
            && str_contains($frame, "\e[K") === false
            && str_contains($frame, "\e[2J") === false,
         description: 'Titles are sanitized — erase escapes never reach the border row'
      );

      // @ Multibyte — UTF-8 visible width clips exactly
      $Accents = new Frame($Host);
      $Accents->width = 8;
      $Accents->height = 3;

      $Accents->Output->render("áéíóúãõ\n");
      $frame = (string) $Accents->render(Frame::RETURN_OUTPUT);
      $rows = explode("\n", trim($frame, "\n"));

      yield assert(
         assertion: $measure($rows[1]) === 8
            && str_contains($frame, 'áéíóúã') === true
            && str_contains($frame, 'õ') === false,
         description: 'Multibyte content clips at the exact visible width'
      );
   }
);
