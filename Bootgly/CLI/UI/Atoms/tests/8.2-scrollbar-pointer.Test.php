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
   description: 'It should hit-test the strip, aim by the thumb center and hover the thumb',
   test: function () {
      $Output = new Output('php://memory');
      $Scrollbar = new Scrollbar($Output);
      $Scrollbar->decoration = true;
      $Scrollbar->row = 10;
      $Scrollbar->column = 20;
      $Scrollbar->height = 4;
      $Scrollbar->total = 16;
      $Scrollbar->first = 12;

      // @ hit() — thumb on its measured row, track elsewhere on the strip
      yield assert(
         assertion: $Scrollbar->hit(column: 20, line: 13) === 'thumb'
            && $Scrollbar->hit(column: 20, line: 10) === 'track'
            && $Scrollbar->hit(column: 20, line: 12) === 'track',
         description: 'The strip resolves thumb and track by the measured geometry'
      );

      // @ hit() — outside: wrong column, above, below
      yield assert(
         assertion: $Scrollbar->hit(column: 19, line: 13) === null
            && $Scrollbar->hit(column: 21, line: 13) === null
            && $Scrollbar->hit(column: 20, line: 9) === null
            && $Scrollbar->hit(column: 20, line: 14) === null,
         description: 'Coordinates off the strip never hit'
      );

      // @ aim() — the thumb center maps the line back to the first index
      yield assert(
         assertion: $Scrollbar->aim(10) === 0
            && $Scrollbar->first === 0
            && $Scrollbar->aim(13) === 12
            && $Scrollbar->first === 12,
         description: 'Aiming the strip edges lands on the first and last view positions'
      );

      // @ aim() — lines beyond the strip clamp to the edges
      yield assert(
         assertion: $Scrollbar->aim(8) === 0
            && $Scrollbar->aim(20) === 12,
         description: 'Aiming beyond the strip clamps to the edges'
      );

      // @ hover(true) — repaints the placed strip with the accented thumb
      $Scrollbar->hover(true);

      rewind($Output->stream);
      $written = (string) stream_get_contents($Output->stream);

      yield assert(
         assertion: $Scrollbar->hovered === true
            && str_contains($written, "\e[13;20H") === true
            && str_contains($written, "\e[97m█\e[0m") === true,
         description: 'Hovering repaints the strip with the accented thumb'
      );

      // @ hover(true) again — idempotent, no repaint
      $position = ftell($Output->stream);
      $Scrollbar->hover(true);

      yield assert(
         assertion: ftell($Output->stream) === $position,
         description: 'An unchanged hover writes nothing'
      );

      // @ hover(false) — leaves and repaints with the rest paint
      $Scrollbar->hover(false);

      yield assert(
         assertion: $Scrollbar->hovered === false
            && ftell($Output->stream) > $position,
         description: 'Leaving the hover repaints with the rest paint'
      );

      // @ reset() — silent: state clears, nothing repaints
      $Scrollbar->hover(true);
      $position = ftell($Output->stream);
      $Scrollbar->reset();

      yield assert(
         assertion: $Scrollbar->hovered === false
            && $Scrollbar->first === 0
            && ftell($Output->stream) === $position,
         description: 'Resetting clears the view state without repainting'
      );

      // @ Not sliding — no hit, aim keeps the first index
      $Scrollbar->total = 4;
      $Scrollbar->first = 2;

      yield assert(
         assertion: $Scrollbar->hit(column: 20, line: 11) === null
            && $Scrollbar->aim(12) === 2,
         description: 'A non-sliding bar hits nothing and never re-aims'
      );

      // @ Unplaced — hits nothing even while sliding
      $Composed = new Scrollbar($Output);
      $Composed->decoration = true;
      $Composed->height = 4;
      $Composed->total = 16;

      yield assert(
         assertion: $Composed->hit(column: 1, line: 1) === null,
         description: 'An unplaced bar hits nothing'
      );

      // @ hover() — plain output has no pointer
      $Plain = new Scrollbar($Output);
      $Plain->decoration = false;
      $Plain->height = 4;
      $Plain->total = 16;
      $Plain->hover(true);

      yield assert(
         assertion: $Plain->hovered === false,
         description: 'Plain decoration never hovers'
      );
   }
);
