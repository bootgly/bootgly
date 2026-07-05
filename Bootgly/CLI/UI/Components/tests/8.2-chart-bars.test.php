<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function count;
use function explode;
use function str_contains;
use function substr_count;
use function trim;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Chart\Plots;


return new Specification(
   description: 'It should plot a series as labeled horizontal bars',
   test: function () {
      // ! Chart with an in-memory stream
      $Output = new Output('php://memory');

      $Chart = new Chart($Output);
      $Chart->Plots = Plots::Bars;
      $Chart->width = 20;
      $Chart->precision = 0;
      $Chart->series = ['bootgly' => 166700.0, 'swoole' => 150000.0, 'workerman' => 83350.0];

      // @ Render as string
      $frame = (string) $Chart->render(Chart::RETURN_OUTPUT);
      $lines = explode("\n", trim($frame));

      // @ Valid
      yield assert(
         assertion: count($lines) === 3,
         description: 'One row renders per series entry'
      );
      yield assert(
         assertion: str_contains($lines[0], 'bootgly') === true && str_contains($lines[0], '166700') === true,
         description: 'Rows render the label and the formatted value'
      );

      // ! Bar lengths scale to the widest value
      $first = substr_count($lines[0], '█');
      $last = substr_count($lines[2], '█');

      yield assert(
         assertion: $first === 20 && $last === 10,
         description: 'Bars scale to the max (166700 → 20 units; 83350 → 10 units)'
      );
   }
);
