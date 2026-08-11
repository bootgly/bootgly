<?php

namespace Bootgly\CLI\UI\Atoms;


use function assert;
use function ftell;
use function rewind;
use function str_contains;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\CLI\Terminal\Output;


return new Test(
   description: 'It should hit-test the rectangle, hover-repaint and press the Action',
   test: function () {
      $Output = new Output('php://memory');
      $Button = new Button($Output);
      $Button->decoration = true;
      $Button->label = 'Save';
      $Button->row = 3;
      $Button->column = 5;
      $Button->width = 6;

      // @ hit() — inside the rectangle, edges included
      yield assert(
         assertion: $Button->hit(column: 5, line: 3) === true
            && $Button->hit(column: 10, line: 3) === true,
         description: 'A coordinate inside the rectangle (edges included) hits'
      );

      // @ hit() — outside: before, after, above, below
      yield assert(
         assertion: $Button->hit(column: 4, line: 3) === false
            && $Button->hit(column: 11, line: 3) === false
            && $Button->hit(column: 5, line: 2) === false
            && $Button->hit(column: 5, line: 4) === false,
         description: 'Coordinates outside the rectangle never hit'
      );

      // @ hit() — an unplaced button hits nothing
      $Unplaced = new Button($Output);
      $Unplaced->label = 'Ghost';

      yield assert(
         assertion: $Unplaced->hit(column: 1, line: 1) === false,
         description: 'An unplaced button hits nothing'
      );

      // @ hover(true) — repaints in place with the hover codes
      $Button->hover(true);

      rewind($Output->stream);
      $written = (string) stream_get_contents($Output->stream);

      yield assert(
         assertion: $Button->hovered === true
            && str_contains($written, "\e[3;5H") === true
            && str_contains($written, "\e[48;2;58;58;58;97m") === true,
         description: 'Hovering repaints at the rectangle with the hover codes'
      );

      // @ hover(true) again — idempotent, no repaint
      $position = ftell($Output->stream);
      $Button->hover(true);

      yield assert(
         assertion: ftell($Output->stream) === $position,
         description: 'An unchanged hover writes nothing'
      );

      // @ hover(false) — leaves and repaints with the rest style
      $Button->hover(false);

      yield assert(
         assertion: $Button->hovered === false
            && ftell($Output->stream) > $position,
         description: 'Leaving the hover repaints with the rest style'
      );

      // @ hover() — plain output has no pointer
      $Plain = new Button($Output);
      $Plain->decoration = false;
      $Plain->label = 'Plain';
      $Plain->row = 1;
      $Plain->column = 1;
      $Plain->width = 7;
      $Plain->hover(true);

      yield assert(
         assertion: $Plain->hovered === false,
         description: 'Plain decoration never hovers'
      );

      // @ press() — null without an Action
      yield assert(
         assertion: $Button->press() === null,
         description: 'Pressing without an Action returns null'
      );

      // @ press() — the Action receives the Button and its return comes back
      $received = null;
      $Button->Action = function (Button $Pressed) use (&$received) {
         $received = $Pressed;

         return 'pressed';
      };

      yield assert(
         assertion: $Button->press() === 'pressed'
            && $received === $Button,
         description: 'The Action receives the Button itself and returns through press()'
      );
   }
);
