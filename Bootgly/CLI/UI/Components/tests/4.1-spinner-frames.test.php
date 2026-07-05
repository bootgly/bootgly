<?php

namespace Bootgly\CLI\UI\Components;


use const BOOTGLY_TTY;
use function assert;
use function rewind;
use function str_contains;
use function stream_get_contents;
use function usleep;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should animate frames tick-driven and finish with a resolution line',
   test: function () {
      // ! Spinner with an in-memory stream
      $Output = new Output('php://memory');

      $Spinner = new Spinner($Output);
      $Spinner->throttle = 0.0;

      // @ Frame render (string mode)
      $Spinner->render = Spinner::RETURN_OUTPUT;
      $Spinner->start('Working...');

      // @ Valid
      yield assert(
         assertion: $Spinner->description === 'Working...',
         description: 'start() records the description'
      );

      $Spinner->render = Spinner::WRITE_OUTPUT;

      // @ Spin advances the frame only on interactive terminals
      $frame = $Spinner->frame;

      usleep(1000);
      $Spinner->spin();

      if (BOOTGLY_TTY === true) {
         yield assert(
            assertion: $Spinner->frame === $frame + 1,
            description: 'spin() advances the animation frame'
         );
      }
      else {
         yield assert(
            assertion: $Spinner->frame === $frame,
            description: 'spin() never animates on non-interactive output'
         );
      }

      // @ Finish renders the resolution line
      $Spinner->finish('done');

      rewind($Output->stream);
      $output = (string) stream_get_contents($Output->stream);

      // @ Valid
      yield assert(
         assertion: $Spinner->finished === true && str_contains($output, 'done') === true,
         description: 'finish() renders the resolution line once'
      );
   }
);
