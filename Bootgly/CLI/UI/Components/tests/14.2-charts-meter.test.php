<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function str_contains;
use function substr_count;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Chart\Gradient;
use Bootgly\CLI\UI\Components\Charts\Meter;


return new Specification(
   description: 'It should gauge a percentage as a gradient-colored cell run',
   test: function () {
      // ! Meter with an in-memory stream
      $Output = new Output('php://memory');

      $Meter = new Meter($Output);
      $Meter->width = 10;
      $Meter->Gradient = new Gradient(['#000000', '#ffffff'], extended: false);

      // @ Half full
      $Meter->value = 50.0;
      $frame = (string) $Meter->render(Meter::RETURN_OUTPUT);

      yield assert(
         assertion: substr_count($frame, '■') === 10,
         description: 'The gauge always renders its full width in cells'
      );
      yield assert(
         assertion: str_contains($frame, "\e[38;2;128;128;128m") === true
            && str_contains($frame, "\e[90m") === true,
         description: 'Filled cells sample the gradient at their position; empty cells render dim'
      );

      // @ Full
      $Meter->value = 100.0;
      $frame = (string) $Meter->render(Meter::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($frame, "\e[90m") === false
            && str_contains($frame, "\e[38;2;255;255;255m") === true,
         description: 'A full gauge has no dim cells and reaches the gradient top'
      );

      // @ Empty
      $Meter->value = 0.0;
      $frame = (string) $Meter->render(Meter::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($frame, '38;2;') === false
            && substr_count($frame, '■') === 10,
         description: 'An empty gauge renders only dim cells'
      );

      // @ Inverted — first cell samples from the high end
      $Meter->inverted = true;
      $Meter->value = 100.0;
      $frame = (string) $Meter->render(Meter::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($frame, "\e[38;2;230;230;230m") === true
            && str_contains($frame, "\e[38;2;0;0;0m") === true,
         description: 'Inverted gauges sample the gradient from 100 down to 0'
      );
   }
);
