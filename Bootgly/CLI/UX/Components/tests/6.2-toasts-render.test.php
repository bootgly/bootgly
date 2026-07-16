<?php

namespace Bootgly\CLI\UX\Components;


use const BOOTGLY_TTY;
use function assert;
use function rewind;
use function str_contains;
use function stream_get_contents;
use function strlen;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Alert\Type;


return new Specification(
   description: 'It should paint the box anchored to the corner and diff-blit idle ticks',
   test: function () {
      Terminal::$columns = 80;
      Terminal::$lines = 24;

      $Host = new Output('php://memory');

      $read = static function () use ($Host): string {
         rewind($Host->stream);

         return (string) stream_get_contents($Host->stream);
      };

      $Toasts = new Toasts($Host);
      $Toasts->add('Saved', Type::Success, TTL: 100.0, at: 0.0);

      // ! 'Saved' (5) → width 11; TopRight → column 80 − 11 + 1 = 70
      $Frame = $Toasts->queue[0]['Frame'];

      $Toasts->render(at: 1.0);

      yield assert(
         assertion: $Frame->row === 1 && $Frame->column === 70
            && $Frame->width === 11 && $Frame->height === 3,
         description: 'The box auto-sizes to the message and anchors to the corner'
      );

      $painted = $read();

      if (BOOTGLY_TTY === true) {
         yield assert(
            assertion: str_contains($painted, "\e[1;70H") === true
               && str_contains($painted, '╭') === true
               && str_contains($painted, '✔') === true
               && str_contains($painted, 'Saved') === true,
            description: 'Interactive rows anchor at the corner with the severity glyph'
         );

         // ? Idle ticks write zero bytes
         $Toasts->render(at: 2.0);

         yield assert(
            assertion: strlen($read()) === strlen($painted),
            description: 'An idle tick with no expiry emits zero bytes'
         );
      }
      else {
         yield assert(
            assertion: $Toasts->render(at: 2.0) === null
               && str_contains($painted, "\e[1;70H") === false,
            description: 'Non-interactive renders position-paint nothing'
         );
      }

      // @ RETURN mode is pure — contains the box, writes nothing new
      $before = strlen($read());
      $returned = (string) $Toasts->render(Toasts::RETURN_OUTPUT, at: 3.0);

      yield assert(
         assertion: str_contains($returned, 'Saved') === true
            && str_contains($returned, '╭') === true
            && strlen($read()) === $before,
         description: 'RETURN mode returns the rectangle without writing'
      );
   }
);
