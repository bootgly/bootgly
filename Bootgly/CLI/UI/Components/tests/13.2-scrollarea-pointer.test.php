<?php

namespace Bootgly\CLI\UI\Components;


use const BOOTGLY_TTY;
use function assert;
use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should hit-test, aim (drag) and hover the scrollbar',
   test: function () {
      if (BOOTGLY_TTY === true) {
         // ! A 4-row band at rows 10-13, 20 columns, 16 buffered rows
         $Output = new Output('php://memory');

         $Scrollarea = new Scrollarea($Output);
         $Scrollarea->row = 10;
         $Scrollarea->rows = 4;
         $Scrollarea->width = 20;
         $Scrollarea->render = Scrollarea::RETURN_OUTPUT;

         for ($index = 0; $index < 16; $index++) {
            $Scrollarea->feed("row {$index}");
         }

         // @ Hit-testing: outside, content, track and thumb
         // ? Stuck at the bottom (first = 12) — the thumb sits at the band end
         yield assert(
            assertion: $Scrollarea->hit(5, 9) === null && $Scrollarea->hit(21, 10) === null,
            description: 'Coordinates outside the band hit nothing'
         );
         yield assert(
            assertion: $Scrollarea->hit(5, 11) === 'content',
            description: 'Coordinates inside the band hit the content'
         );
         yield assert(
            assertion: $Scrollarea->hit(20, 10) === 'track' && $Scrollarea->hit(20, 13) === 'thumb',
            description: 'The scrollbar column hits the track or the thumb (stuck = thumb at the end)'
         );

         // @ Aiming: dragging the thumb to the band top scrolls to the first rows
         $Scrollarea->aim(10);

         yield assert(
            assertion: $Scrollarea->first === 0 && $Scrollarea->stuck === false,
            description: 'Aiming the band top unsticks and scrolls to the first rows'
         );

         // @ Aiming the band bottom sticks the view back
         $Scrollarea->aim(13);

         yield assert(
            assertion: $Scrollarea->first === 12 && $Scrollarea->stuck === true,
            description: 'Aiming the band bottom scrolls to the newest rows and re-sticks'
         );

         // @ Hovering highlights the thumb (bright white instead of bright black)
         $Scrollarea->hover(true);
         $frame = (string) $Scrollarea->render(Scrollarea::RETURN_OUTPUT);

         yield assert(
            assertion: $Scrollarea->hovered === true && str_contains($frame, "\e[97m█\e[0m") === true,
            description: 'A hovered thumb renders highlighted'
         );

         $Scrollarea->hover(false);
         $frame = (string) $Scrollarea->render(Scrollarea::RETURN_OUTPUT);

         yield assert(
            assertion: $Scrollarea->hovered === false && str_contains($frame, "\e[90m█\e[0m") === true,
            description: 'Leaving the thumb restores the dim render'
         );
      }
      else {
         // ! Pipes: nothing is buffered — the band has no scrollbar to hit
         $Output = new Output('php://memory');

         $Scrollarea = new Scrollarea($Output);
         $Scrollarea->row = 10;
         $Scrollarea->rows = 4;
         $Scrollarea->width = 20;

         $Scrollarea->feed('plain row');

         // @ Valid
         yield assert(
            assertion: $Scrollarea->hit(20, 10) === 'content' && $Scrollarea->hit(5, 9) === null,
            description: 'Without an overflowing buffer the scrollbar column hits the content'
         );
         yield assert(
            assertion: $Scrollarea->hovered === false,
            description: 'The hover state stays off on non-interactive output'
         );
      }
   }
);
