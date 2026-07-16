<?php

namespace Bootgly\CLI\UX\Components;


use const BOOTGLY_TTY;
use function assert;
use function fopen;
use function rewind;
use function str_contains;
use function stream_get_contents;
use RuntimeException;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Timeline\States;


return new Specification(
   description: 'It should fail the flow when a handler throws — later steps stay Pending',
   test: function () {
      // ! Wizard with in-memory streams
      $Input = new Input(fopen('php://memory', 'r+')); // @phpstan-ignore-line
      $Output = new Output('php://memory');

      $ran = [];

      $Wizard = new Wizard($Input, $Output);
      $One = $Wizard->add('One', function (Wizard $Wizard) use (&$ran) {
         $ran[] = 'One';
         return null;
      });
      $Two = $Wizard->add('Two', function (Wizard $Wizard) use (&$ran) {
         $ran[] = 'Two';
         throw new RuntimeException('boom');
      });
      $Three = $Wizard->add('Three', function (Wizard $Wizard) use (&$ran) {
         $ran[] = 'Three';
         return null;
      });

      // @ Run stops at the failure
      $done = $Wizard->run();

      rewind($Output->stream);
      $output = (string) stream_get_contents($Output->stream);

      yield assert(
         assertion: $done === false && $Wizard->finished === true,
         description: 'run() returns false on failure and finishes the flow'
      );
      yield assert(
         assertion: $Wizard->Throwable?->getMessage() === 'boom',
         description: 'The Throwable that failed the flow is exposed'
      );
      yield assert(
         assertion: $One->State === States::Done
            && $Two->State === States::Failed && $Two->note === 'boom'
            && $Three->State === States::Pending,
         description: 'States end Done / Failed / Pending — the message becomes the ✖ note'
      );
      yield assert(
         assertion: $ran === ['One', 'Two'],
         description: 'Handlers after the failure never run'
      );

      if (BOOTGLY_TTY === true) {
         yield assert(
            assertion: str_contains($output, '✖ Two'),
            description: 'The final frame marks the failed step'
         );
      }
      else {
         yield assert(
            assertion: str_contains($output, '✖ Two (boom)')
               && str_contains($output, '◉ Three') === false,
            description: 'Non-interactive output appends the failure — later steps never activate'
         );
      }
   }
);
