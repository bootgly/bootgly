<?php

namespace Bootgly\CLI\UX\Components;


use const BOOTGLY_TTY;
use function assert;
use function rewind;
use function str_contains;
use function stream_get_contents;
use function strlen;
use function substr;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UX\Components\Toasts\Positions;


return new Specification(
   description: 'It should repaint a hidden toast re-entering a slot another toast occupied',
   test: function () {
      Terminal::$columns = 80;
      Terminal::$lines = 24;

      $Host = new Output('php://memory');

      $read = static function () use ($Host): string {
         rewind($Host->stream);

         return (string) stream_get_contents($Host->stream);
      };

      $Toasts = new Toasts($Host);
      $Toasts->Positions = Positions::TopLeft;
      $Toasts->limit = 1;

      // ! Equal-length messages → identical widths, so the single slot's rect tuple
      //   never changes as the window shifts. A is PAINTED first (its Frame front
      //   caches 'AAAA' at row 1), then hidden while B paints 'BBBB' over the same
      //   cells; when A re-enters, its own front still matches 'AAAA' — the reflow
      //   must key on occupant identity, not geometry, or A writes zero bytes and
      //   the screen keeps showing the stale 'BBBB'
      $Toasts->add('AAAA', TTL: 100.0, at: 0.0);
      $Toasts->render(at: 0.1);

      // @ B evicts A from the single visible slot and paints over its cells
      $Toasts->add('BBBB', TTL: 1.0, at: 0.1);
      $Toasts->render(at: 0.2);

      yield assert(
         assertion: $Toasts->queue[1]['message'] === 'BBBB'
            && $Toasts->queue[0]['Frame']->row === 1,
         description: 'The newest toast evicts the oldest from the single slot'
      );

      // @ B expires — A re-enters row 1, where B's content still sits
      $painted = strlen($read());
      $Toasts->render(at: 2.0);
      $delta = substr($read(), $painted);

      if (BOOTGLY_TTY === true) {
         yield assert(
            assertion: str_contains($delta, "\e[1;1H") === true
               && str_contains($delta, 'AAAA') === true,
            description: 'The re-entering toast repaints its message over the stale slot'
         );
      }
      else {
         yield assert(
            assertion: $delta === '',
            description: 'Non-interactive reflows write nothing'
         );
      }
   }
);
