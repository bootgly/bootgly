<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function rewind;
use function str_contains;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should return the markup frame publicly without writing',
   test: function () {
      // ! Timeline with an in-memory stream
      $Output = new Output('php://memory');

      $Timeline = new Timeline($Output);
      $Timeline->add('Mode');
      $Timeline->add('Path');

      // @ Return mode exposes the raw markup frame
      $frame = $Timeline->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains((string) $frame, '@#Black:○ Mode@;')
            && str_contains((string) $frame, '@#Black:○ Path@;'),
         description: 'Pending steps render with the muted (gray) markup'
      );

      // @ Activating a step is reflected on the next returned frame
      $Timeline->Steps->activate();

      $frame = $Timeline->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains((string) $frame, '@#Cyan:◉ Mode@;')
            && str_contains((string) $frame, '@#Black:○ Path@;'),
         description: 'Active steps render with the active markup'
      );

      // @ Valid — no write side effect on the Output stream
      rewind($Output->stream);
      $written = (string) stream_get_contents($Output->stream);

      yield assert(
         assertion: $written === '',
         description: 'Return mode never writes to the Output'
      );

      // @ The render property pins the mode for plain calls
      $Timeline->render = Component::RETURN_OUTPUT;

      yield assert(
         assertion: str_contains((string) $Timeline->render(), '@#Cyan:◉ Mode@;'),
         description: 'The $render property pins RETURN_OUTPUT for mode-less calls'
      );
   }
);
