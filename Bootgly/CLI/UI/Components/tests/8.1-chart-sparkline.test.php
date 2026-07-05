<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function str_contains;
use function trim;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Charts\Sparkline;


return new Specification(
   description: 'It should plot a series as a one-line sparkline',
   test: function () {
      // ! Chart with an in-memory stream
      $Output = new Output('php://memory');

      $Chart = new Sparkline($Output);
      $Chart->series = ['a' => 1.0, 'b' => 4.0, 'c' => 8.0, 'd' => 2.0];

      // @ Render as string
      $frame = (string) $Chart->render(Sparkline::RETURN_OUTPUT);

      // @ Valid
      yield assert(
         assertion: $Chart->max === 8.0 && $Chart->min === 1.0,
         description: 'Series bounds resolve (min 1, max 8)'
      );
      yield assert(
         assertion: str_contains($frame, '▁') === true && str_contains($frame, '█') === true,
         description: 'The min maps to the lowest glyph and the max to the highest'
      );

      // @ Flat series render mid-level glyphs (no division by zero)
      $Chart->series = ['a' => 5.0, 'b' => 5.0];

      $frame = (string) $Chart->render(Sparkline::RETURN_OUTPUT);

      yield assert(
         assertion: trim($frame) !== '' && str_contains($frame, '█') === false,
         description: 'Flat series plot mid-level glyphs'
      );

      // @ Empty series render nothing
      $Chart->series = [];

      yield assert(
         assertion: $Chart->render(Sparkline::RETURN_OUTPUT) === null,
         description: 'An empty series is a no-op'
      );
   }
);
