<?php

namespace Bootgly\CLI\UI\Components;


use const BOOTGLY_TTY;
use function assert;
use function count;
use function rewind;
use function str_contains;
use function stream_get_contents;
use function substr_count;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should buffer, wrap, scroll and stick a content band with a scrollbar',
   test: function () {
      if (BOOTGLY_TTY === true) {
         $Output = new Output('php://memory');

         $Scrollarea = new Scrollarea($Output);
         $Scrollarea->row = 1;
         $Scrollarea->rows = 3;
         $Scrollarea->width = 11; // 10 inner columns + scrollbar

         // @ Wrapping: a 25-column line becomes 3 visual rows (10 + 10 + 5)
         $Scrollarea->feed('abcdefghijklmnopqrstuvwxy');

         yield assert(
            assertion: count($Scrollarea->buffer) === 3
               && $Scrollarea->buffer[0] === 'abcdefghij'
               && $Scrollarea->buffer[2] === 'uvwxy',
            description: 'Long lines wrap into visual rows at the band inner width'
         );

         // @ SGR carry: colored content reopens its color on the wrapped row
         $Scrollarea->feed('@#Cyan:0123456789AB@;');

         yield assert(
            assertion: str_contains($Scrollarea->buffer[3], "\e[96m") === true
               && str_contains($Scrollarea->buffer[3], "\e[0m") === true
               && str_contains($Scrollarea->buffer[4], "\e[96m") === true,
            description: 'The active SGR closes at the row end and carries into the next row'
         );

         // @ Stick: the view follows the newest rows
         yield assert(
            assertion: $Scrollarea->stuck === true
               && $Scrollarea->first === count($Scrollarea->buffer) - 3,
            description: 'Stuck to the bottom, feeds slide the window to the newest rows'
         );

         // @ Scroll up holds the position while new rows arrive
         $Scrollarea->scroll(-10);

         yield assert(
            assertion: $Scrollarea->first === 0 && $Scrollarea->stuck === false,
            description: 'Scrolling up clamps at the top and unsticks the view'
         );

         $Scrollarea->feed('new line');

         yield assert(
            assertion: $Scrollarea->first === 0,
            description: 'New feeds hold the scrolled position while unstuck'
         );

         // @ The frame renders the visible window + scrollbar
         $frame = (string) $Scrollarea->render(Scrollarea::RETURN_OUTPUT);

         yield assert(
            assertion: str_contains($frame, 'abcdefghij') === true
               && str_contains($frame, 'new line') === false
               && (substr_count($frame, '█') + substr_count($frame, '│')) === 3,
            description: 'The frame shows the scrolled window with one scrollbar cell per row'
         );

         // @ Scroll down to the end re-sticks
         $Scrollarea->scroll(+100);

         yield assert(
            assertion: $Scrollarea->stuck === true,
            description: 'Scrolling to the last row sticks the view back to the bottom'
         );
      }
      else {
         // ! Pipes: plain writes, no band repaint
         $Output = new Output('php://memory');

         $Scrollarea = new Scrollarea($Output);
         $Scrollarea->feed('plain content');

         rewind($Output->stream);
         $written = (string) stream_get_contents($Output->stream);

         // @ Valid
         yield assert(
            assertion: str_contains($written, 'plain content') === true
               && $Scrollarea->buffer === [],
            description: 'Non-interactive output writes plainly without buffering'
         );
         yield assert(
            assertion: str_contains($written, "\e[7") === false,
            description: 'No band repaint escapes leak into pipes'
         );
      }
   }
);
