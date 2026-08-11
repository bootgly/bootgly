<?php

namespace Bootgly\CLI\UI\Base;


use function assert;
use function explode;
use function fopen;
use function str_contains;
use function trim;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Test(
   description: 'It should hover, click and wheel the bar labels by pointer',
   test: function () {
      $stream = fopen('php://memory', 'r+');
      $Input = new Input($stream); // @phpstan-ignore-line
      $Host = new Output('php://memory');

      // ! Geometry: row 1, column 1, width 40 — the strip starts at screen
      //   column 3 (corner glyph + title pad). Labels: Log [3..7],
      //   CPU [9..13], Table [15..21] in absolute columns
      $Tabs = new Tabs($Input, $Host);
      $Tabs->width = 40;
      $Tabs->height = 6;

      $Tabs->add('Log');
      $Tabs->add('CPU');
      $Tabs->add('Table');

      // @ Hit-testing — labels hit, divisors/corners/other rows miss
      yield assert(
         assertion: $Tabs->hit(3, 1) === 1 && $Tabs->hit(10, 1) === 2
            && $Tabs->hit(16, 1) === 3,
         description: 'A bar-row position over a label resolves its ordinal'
      );
      yield assert(
         assertion: $Tabs->hit(2, 1) === 0 && $Tabs->hit(8, 1) === 0
            && $Tabs->hit(10, 3) === 0 && $Tabs->hit(39, 1) === 0,
         description: 'The corner, the divisors, other rows and the fill miss'
      );

      // @ Movement hovers — the label accents with the hover paint
      yield assert(
         assertion: $Tabs->control("\e[<35;10;1M") === true && $Tabs->hovered === 2,
         description: 'Plain movement over a label hovers its ordinal'
      );

      $frame = (string) $Tabs->render(Tabs::RETURN_OUTPUT);
      $rows = explode("\n", trim($frame, "\n"));

      yield assert(
         assertion: str_contains($rows[0], "\e[4m CPU \e[0m") === true
            && str_contains($rows[0], "\e[7;1m Log \e[0m") === true,
         description: 'The hovered label underlines while the active keeps its highlight'
      );

      // @ Leaving clears — a divisor position and another row both leave
      $Tabs->control("\e[<35;8;1M");
      $left = $Tabs->hovered;
      $Tabs->control("\e[<35;10;1M");
      $Tabs->control("\e[<35;10;3M");

      yield assert(
         assertion: $left === 0 && $Tabs->hovered === 0,
         description: 'Movement off the labels clears the hover'
      );

      // @ A left press switches; the release and a miss never do
      $Tabs->control("\e[<0;16;1M");
      $switched = $Tabs->tab;
      $Tabs->control("\e[<0;10;1m");
      $released = $Tabs->tab;
      $Tabs->control("\e[<0;30;1M");

      yield assert(
         assertion: $switched === 3 && $released === 3 && $Tabs->tab === 3,
         description: 'A label press activates its tab — releases and misses are no-ops'
      );

      // @ The wheel cycles over the bar row only
      $Tabs->control("\e[<64;5;1M");
      $up = $Tabs->tab;
      $Tabs->control("\e[<65;5;1M");
      $down = $Tabs->tab;
      $Tabs->control("\e[<65;5;4M");

      yield assert(
         assertion: $up === 2 && $down === 3 && $Tabs->tab === 3,
         description: 'The wheel cycles the tabs over the bar and idles elsewhere'
      );

      // @ Malformed reports and quits keep the control contract
      yield assert(
         assertion: $Tabs->control("\e[<0;16M") === true
            && $Tabs->control('q') === false && $Tabs->tab === 3,
         description: 'Malformed reports are ignored and `q` still ends the session'
      );
   }
);
