<?php

namespace Bootgly\CLI\UX\Components;


use const BOOTGLY_TTY;
use function assert;
use function fopen;
use function rewind;
use function str_contains;
use function stream_get_contents;
use function substr_count;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Timeline\States;


return new Specification(
   description: 'It should run steps forward-only (one-shot), noting each transition',
   test: function () {
      // ! Wizard with in-memory streams
      $Input = new Input(fopen('php://memory', 'r+')); // @phpstan-ignore-line
      $Output = new Output('php://memory');

      $Wizard = new Wizard($Input, $Output);
      $Wizard->title = 'Flow';
      $One = $Wizard->add('One', function (Wizard $Wizard) {
         return 'n1';
      });
      $Two = $Wizard->add('Two', function (Wizard $Wizard) {
         return null;
      });

      // @ Run completes the flow
      $done = $Wizard->run();

      rewind($Output->stream);
      $output = (string) stream_get_contents($Output->stream);

      yield assert(
         assertion: $done === true && $Wizard->finished === true,
         description: 'run() completes the flow'
      );
      yield assert(
         assertion: $One->State === States::Done && $One->note === 'n1'
            && $Two->State === States::Done && $Two->note === '',
         description: 'Steps end Done — the returned string becomes the step note'
      );

      if (BOOTGLY_TTY === true) {
         yield assert(
            assertion: str_contains($output, '○ Two') && str_contains($output, '◉ One'),
            description: 'The first frame marks the future step muted (○) below the active one (◉)'
         );
         yield assert(
            assertion: substr_count($output, '✔') >= 3 && str_contains($output, '(n1)'),
            description: 'Frames reprint past steps with the green checkmark and their notes'
         );
         yield assert(
            assertion: substr_count($output, 'Flow') >= 2 && substr_count($output, "\e[H") >= 2,
            description: 'Each activation repaints a fresh screen headed by the title'
         );
      }
      else {
         yield assert(
            assertion: $output === "Flow\n◉ One\n✔ One (n1)\n◉ Two\n✔ Two\n",
            description: 'Non-interactive output renders the title once and appends one plain line per transition'
         );
      }

      // @ One-shot: re-running is a no-op
      yield assert(
         assertion: $Wizard->run() === false,
         description: 'run() never re-runs a finished flow'
      );

      // @ Empty wizards never run
      $Empty = new Wizard($Input, $Output);

      yield assert(
         assertion: $Empty->run() === false && $Empty->finished === false,
         description: 'run() refuses an empty flow'
      );
   }
);
