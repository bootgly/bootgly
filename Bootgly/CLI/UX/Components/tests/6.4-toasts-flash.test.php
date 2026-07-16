<?php

namespace Bootgly\CLI\UX\Components;


use const BOOTGLY_TTY;
use function assert;
use function count;
use function microtime;
use function rewind;
use function str_contains;
use function str_repeat;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should block a flash until its toast expires and restore the screen',
   test: function () {
      Terminal::$columns = 80;
      Terminal::$lines = 24;

      $Host = new Output('php://memory');

      $Toasts = new Toasts($Host);
      $Toasts->throttle = 0.01;

      $started = microtime(true);
      $Toasts->flash('Bye', TTL: 0.05);
      $elapsed = microtime(true) - $started;

      rewind($Host->stream);
      $output = (string) stream_get_contents($Host->stream);

      yield assert(
         assertion: count($Toasts->queue) === 0,
         description: 'The flash toast expires before the call returns'
      );

      if (BOOTGLY_TTY === true) {
         yield assert(
            assertion: $elapsed >= 0.05 && $elapsed < 1.0,
            description: 'The call blocks for the toast lifetime'
         );

         // ! 'Bye' (3) → width 9; TopRight → column 80 − 9 + 1 = 72
         yield assert(
            assertion: str_contains($output, '╭') === true
               && str_contains($output, 'Bye') === true
               && str_contains($output, "\e[1;72H") === true,
            description: 'The flash paints the box anchored to the corner'
         );

         // ? The expiry blanks the box — a literal-space run at its anchor after
         //   the painted content (not satisfiable by the initial paint alone)
         yield assert(
            assertion: str_contains($output, "\e[1;72H" . str_repeat(' ', 9)) === true,
            description: 'The final tick blanks the box on expiry'
         );
      }
      else {
         yield assert(
            assertion: $elapsed < 0.05,
            description: 'Non-interactive flashes return immediately'
         );

         yield assert(
            assertion: str_contains($output, "[DEFAULT] Bye\n") === true
               && str_contains($output, '╭') === false,
            description: 'Non-interactive flashes stream the plain line only'
         );
      }
   }
);
