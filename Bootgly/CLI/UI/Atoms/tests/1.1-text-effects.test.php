<?php

namespace Bootgly\CLI\UI\Atoms;


use const BOOTGLY_TTY;
use function assert;
use function rewind;
use function str_contains;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Atoms\Text\Effects;


return new Specification(
   description: 'It should animate text effects and render the final frame on pipes',
   test: function () {
      // ! Text with an in-memory stream
      $Output = new Output('php://memory');

      $Text = new Text($Output);
      $Text->Effects = Effects::Typewriter;
      $Text->interval = 0;
      $Text->content = 'Bootgly';

      // @ One-shot play (typewriter chars on TTY; final frame on pipes)
      $Text->play();

      rewind($Output->stream);
      $output = (string) stream_get_contents($Output->stream);

      yield assert(
         assertion: str_contains($output, 'Bootgly') === true,
         description: 'play() writes the full content (' . (BOOTGLY_TTY ? 'paced' : 'final frame') . ')'
      );

      // @ Shimmer lifecycle (tick-driven)
      $Output = new Output('php://memory');

      $Shimmer = new Text($Output);
      $Shimmer->Effects = Effects::Shimmer;
      $Shimmer->interval = 0;
      $Shimmer->content = 'Loading';

      $Shimmer->start();
      $Shimmer->tick();
      $Shimmer->tick();
      $Shimmer->finish();

      rewind($Output->stream);
      $output = (string) stream_get_contents($Output->stream);

      // @ Valid
      yield assert(
         assertion: $Shimmer->finished === true && str_contains($output, 'Loading') === true,
         description: 'Shimmer starts, ticks and finishes with the plain final frame'
      );

      if (BOOTGLY_TTY === true) {
         yield assert(
            assertion: $Shimmer->frame >= 2,
            description: 'tick() advances the shimmer wave on interactive terminals'
         );
      }
      else {
         yield assert(
            assertion: $Shimmer->frame === 0,
            description: 'Non-interactive output never animates (final frame only)'
         );
      }
   }
);
