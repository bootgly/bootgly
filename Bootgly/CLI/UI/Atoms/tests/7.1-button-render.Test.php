<?php

namespace Bootgly\CLI\UI\Atoms;


use function assert;
use function mb_strlen;
use function mb_strwidth;
use function preg_replace;
use function rewind;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;


return new Test(
   description: 'It should compose the button row: bare, pill, icon, width and plain',
   test: function () {
      $Output = new Output('php://memory');
      $Button = new Button($Output);
      $Button->decoration = true;

      // @ Bare label — no style codes, one breathing space each side, width stored back
      $Button->label = 'Save';
      $row = (string) $Button->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: $row === ' Save '
            && $Button->width === 6,
         description: 'A bare label renders padded with the derived width stored back'
      );

      // @ Pill — SGR codes wrap the row, reset closes it
      $Button->style = ['44', '37'];
      $row = (string) $Button->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_starts_with($row, "\e[44;37m") === true
            && str_contains($row, ' Save ') === true
            && str_ends_with($row, "\e[0m") === true,
         description: 'Style codes paint the pill with a closing reset'
      );

      // @ Icon-only — emoji counts its real (double) width
      $Button->style = [];
      $Button->label = '';
      $Button->icon = '💾';
      $Button->width = 0;
      $row = (string) $Button->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: $row === ' 💾 '
            && $Button->width === 4,
         description: 'An icon-only button derives the emoji double width'
      );

      // @ Icon + label — one space between them inside the pill
      $Button->label = 'Save';
      $Button->width = 0;
      $row = (string) $Button->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: $row === ' 💾 Save '
            && $Button->width === 9,
         description: 'Icon and label compose with a single separating space'
      );

      // @ Explicit width — shorter content pads to the width
      $Button->icon = '';
      $Button->label = 'Go';
      $Button->width = 12;
      $row = (string) $Button->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: mb_strlen($row) === 12
            && str_starts_with($row, ' Go ') === true
            && str_ends_with($row, ' ') === true
            && $Button->width === 12,
         description: 'An explicit width pads shorter content without overwriting it'
      );

      // @ Explicit width — longer content crops with an ellipsis
      $Button->label = 'Documentation';
      $Button->width = 4;
      $row = (string) $Button->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: mb_strwidth((string) preg_replace('/\e\[[0-9;]*m/', '', $row)) === 4
            && str_contains($row, '…') === true,
         description: 'An explicit width crops longer content with an ellipsis'
      );

      // @ Plain — zero escapes even with style codes assigned
      $Button->decoration = false;
      $Button->style = ['44', '37'];
      $Button->label = 'Save';
      $Button->width = 0;
      $row = (string) $Button->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($row, "\e") === false
            && str_contains($row, ' Save ') === true,
         description: 'Plain decoration strips every escape from the row'
      );

      // @ Empty content — renders nothing, width resets
      $Button->label = '';
      $Button->icon = '';
      $row = $Button->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: $row === ''
            && $Button->width === 0,
         description: 'Without icon and label the button renders nothing'
      );

      // @ WRITE_OUTPUT unplaced — writes the row + newline in flow
      $Button->label = 'Flow';
      $Button->render();

      rewind($Output->stream);
      $written = (string) stream_get_contents($Output->stream);

      yield assert(
         assertion: str_contains($written, ' Flow ') === true
            && str_ends_with($written, "\n") === true,
         description: 'An unplaced WRITE_OUTPUT writes the row with a trailing newline'
      );
   }
);
