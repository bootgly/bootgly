<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function rewind;
use function str_contains;
use function stream_get_contents;
use function usleep;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should count down on the wall clock and finish at zero',
   test: function () {
      // ! Timer with an in-memory stream (40ms countdown)
      $Output = new Output('php://memory');

      $Timer = new Timer($Output);
      $Timer->seconds = 0.04;
      $Timer->throttle = 0.0;

      $Timer->start('Deploying');

      // @ Valid
      yield assert(
         assertion: $Timer->remaining === 0.04 && $Timer->finished === false,
         description: 'start() arms the countdown with the configured seconds'
      );

      // @ Tick before zero keeps counting
      usleep(10_000);
      $Timer->tick();

      yield assert(
         assertion: $Timer->remaining > 0.0 && $Timer->remaining < 0.04 && $Timer->finished === false,
         description: 'tick() derives the remaining time from the wall clock'
      );

      // @ Tick past zero finishes
      usleep(40_000);
      $Timer->tick();

      // @ Valid
      yield assert(
         assertion: $Timer->finished === true && $Timer->remaining === 0.0 && $Timer->percent === 100.0,
         description: 'Reaching zero finishes the countdown (remaining 0, percent 100)'
      );

      rewind($Output->stream);
      $output = (string) stream_get_contents($Output->stream);

      yield assert(
         assertion: str_contains($output, '0.00') === true && str_contains($output, 'Deploying') === true,
         description: 'The final frame renders the zeroed remaining time'
      );
   }
);
