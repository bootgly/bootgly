<?php

namespace Bootgly\CLI\UI\Base;


use function assert;
use function str_contains;
use function substr_count;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\CLI\Terminal\Output;


return new Test(
   description: 'It should compose an anchored option list, windowed around the aim',
   test: function () {
      $Output = new Output('php://memory');

      $Flyout = new Flyout($Output);
      $Flyout->options = ['/help', '/clear', '/model', '/exit'];

      // @ Closed while there is nothing to offer
      $Flyout->options = [];

      yield assert(
         assertion: $Flyout->render(Flyout::RETURN_OUTPUT) === '' && $Flyout->height === 0,
         description: 'An empty list renders nothing and reports a height of 0'
      );

      // @ Every option renders when the viewport is unlimited
      $Flyout->options = ['/help', '/clear', '/model', '/exit'];
      $frame = (string) $Flyout->render(Flyout::RETURN_OUTPUT);

      yield assert(
         assertion: $Flyout->height === 4
            && str_contains($frame, '/help') === true
            && str_contains($frame, '/exit') === true
            && str_contains($frame, 'more') === false,
         description: 'An unlimited viewport renders every option, with no hidden-count markers'
      );

      // @ The aim marks its row
      $Flyout->aim(1);
      $frame = (string) $Flyout->render(Flyout::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($frame, '@#Cyan:=> /clear@;') === true
            && str_contains($frame, '   /help') === true,
         description: 'The aimed option carries the marker; the others carry the filler'
      );

      // @ Aiming clamps — the list never wraps
      $Flyout->aim(99);

      yield assert(
         assertion: $Flyout->aimed === 3,
         description: 'aim() clamps past the end'
      );

      $Flyout->advance();

      yield assert(
         assertion: $Flyout->aimed === 3,
         description: 'advance() stops at the last option'
      );

      $Flyout->aim(0);
      $Flyout->regress();

      yield assert(
         assertion: $Flyout->aimed === 0,
         description: 'regress() stops at the first option'
      );

      // @ A viewport windows around the aim and announces what it hides
      $Flyout->viewport = 2;
      $Flyout->aim(3);
      $frame = (string) $Flyout->render(Flyout::RETURN_OUTPUT);

      yield assert(
         assertion: $Flyout->height === 3
            && str_contains($frame, '↑ 2 more') === true
            && str_contains($frame, '/model') === true
            && str_contains($frame, '/exit') === true
            && str_contains($frame, '/help') === false,
         description: 'The window slides to the aim and marks the rows it clipped above'
      );

      $Flyout->aim(0);
      $frame = (string) $Flyout->render(Flyout::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($frame, '↓ 2 more') === true
            && str_contains($frame, '↑') === false,
         description: 'Aiming back to the top marks the rows clipped below'
      );

      // @ Long options crop instead of wrapping
      $Flyout->viewport = 0;
      $Flyout->width = 8;
      $Flyout->options = ['a-very-long-option-label'];
      $Flyout->aim(0);
      $frame = (string) $Flyout->render(Flyout::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($frame, '…') === true
            && str_contains($frame, 'a-very-long-option-label') === false,
         description: 'Options wider than the available columns crop with an ellipsis'
      );

      // @ The bordered composition delegates the box to the Fieldset
      $Flyout->width = null;
      $Flyout->bordered = true;
      $Flyout->title = 'Commands';
      $Flyout->options = ['/help', '/exit'];
      $frame = (string) $Flyout->render(Flyout::RETURN_OUTPUT);

      yield assert(
         assertion: $Flyout->height === 4
            && substr_count($frame, "\n") === 4
            && str_contains($frame, '┌') === true
            && str_contains($frame, '└') === true
            && str_contains($frame, 'Commands') === true,
         description: 'Bordered mode wraps the rows in a titled box and counts its two border rows'
      );
   }
);
