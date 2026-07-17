<?php

namespace Bootgly\CLI\UI\Components;


use const BOOTGLY_TTY;
use function assert;
use function rewind;
use function str_contains;
use function stream_get_contents;
use function usleep;
use ValueError;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Atoms\Text\Effects;


return new Specification(
   description: 'It should support named sets, live status, tips and text effects',
   test: function () {
      // @ Named animation sets — the registry writes $frames
      $Spinner = new Spinner(new Output('php://memory'));
      $Spinner->set = 'star';

      yield assert(
         assertion: $Spinner->frames === Spinner::$Sets['star']
            && $Spinner->frames[1] === '✳',
         description: 'A named set resolves its frames from the Spinner::$Sets registry'
      );

      $caught = false;
      try {
         $Spinner->set = 'unknown-set';
      }
      catch (ValueError) {
         $caught = true;
      }

      yield assert(
         assertion: $caught === true,
         description: 'Unknown set names throw a ValueError'
      );

      // @ Custom registered set
      Spinner::$Sets['test.pulse'] = ['◐', '◓', '◑', '◒'];
      $Spinner->set = 'test.pulse';

      yield assert(
         assertion: $Spinner->frames[0] === '◐',
         description: 'Registered custom sets resolve by name'
      );

      unset(Spinner::$Sets['test.pulse']);

      // @ Status, tips and effect — read back from the Output stream
      $Output = new Output('php://memory');

      $Spinner = new Spinner($Output);
      $Spinner->throttle = 0.0;
      $Spinner->status = '@elapsed; · ↓ 2.1k tokens';
      $Spinner->tips = ['Tip: drive spin() from the working loop.'];
      $Spinner->effect = Effects::Shimmer;
      $Spinner->start('Processing…');

      usleep(1000);
      $Spinner->spin();

      // ? The next repaint carries the reassigned status (live update)
      $Spinner->status = '@elapsed; · ↓ 4.7k tokens';

      usleep(1000);
      $Spinner->spin();

      $Spinner->finish('done');

      rewind($Output->stream);
      $output = (string) stream_get_contents($Output->stream);

      if (BOOTGLY_TTY === true) {
         yield assert(
            assertion: str_contains($output, '(0s · ↓ 2.1k tokens)') === true,
            description: 'The status renders between parentheses with the elapsed token resolved'
         );

         yield assert(
            assertion: str_contains($output, '↓ 4.7k tokens') === true,
            description: 'Status reassignment updates the next repaint'
         );

         yield assert(
            assertion: str_contains($output, '└ Tip: drive spin() from the working loop.') === true,
            description: 'The tip renders as a dim guide row below the spinner'
         );

         yield assert(
            assertion: str_contains($output, "\e[97m") === true
               && str_contains($output, 'Processing…') === false,
            description: 'Shimmer splits the description into painted wave segments'
         );
      }
      else {
         // @ Non-interactive: description once, no status/tips/escape decorations
         yield assert(
            assertion: str_contains($output, 'Processing…') === true
               && str_contains($output, '(') === false
               && str_contains($output, '└') === false,
            description: 'Non-interactive output renders the plain description only'
         );
      }
   }
);
