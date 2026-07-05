<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function explode;
use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should lay multi-bar tracks out in grid columns',
   test: function () {
      // ! Progress with an in-memory stream (frame captured as string)
      $Output = new Output('php://memory');

      $Progress = new Progress($Output);
      $Progress->throttle = 0.0;
      $Progress->columns = 2;
      $Progress->render = Progress::RETURN_OUTPUT;

      for ($index = 0; $index < 4; $index++) {
         $Bar = $Progress->Bars->add("T{$index}");
         $Bar->total = 10;
         $Bar->units = 4;
      }

      // @ Render the grid frame
      $Progress->start();

      $frame = $Progress->output;

      // ! First grid row: T0 and T1 on one line, T2 on the next
      $row = '';
      foreach (explode("\n", $frame) as $line) {
         if (str_contains($line, 'T0') === true) {
            $row = $line;
            break;
         }
      }

      // @ Valid
      yield assert(
         assertion: $row !== '' && str_contains($row, 'T1') === true,
         description: 'Grid rows join N tracks per visual line'
      );
      yield assert(
         assertion: str_contains($row, 'T2') === false,
         description: 'Line breaks split the grid every N tracks'
      );

      // @ Single-bar template path stays byte-compatible (no Bars added)
      $Output = new Output('php://memory');

      $Single = new Progress($Output);
      $Single->total = 10;
      $Single->render = Progress::RETURN_OUTPUT;

      $Single->start();

      yield assert(
         assertion: str_contains($Single->output, '[') === true && str_contains($Single->output, '0.00') === true,
         description: 'Without track Bars, the classic single-bar template renders'
      );
   }
);
