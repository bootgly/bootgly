<?php

namespace Bootgly\CLI\UX\Components;


use const BOOTGLY_TTY;
use function assert;
use function count;
use function rewind;
use function str_contains;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Alert\Type;
use Bootgly\CLI\UX\Components\Toasts\Positions;


return new Specification(
   description: 'It should queue toasts with deadlines and expire them on the clock',
   test: function () {
      Terminal::$columns = 80;
      Terminal::$lines = 24;

      $Host = new Output('php://memory');

      $Toasts = new Toasts($Host);

      // @ Defaults
      yield assert(
         assertion: $Toasts->TTL === 3.0
            && $Toasts->limit === 3
            && $Toasts->Positions === Positions::TopRight
            && $Toasts->width === null
            && $Toasts->gap === 0,
         description: 'The configuration defaults match the contract'
      );

      // @ Queueing with an injected clock
      $Toasts->add('Saved', Type::Success, at: 10.0);
      $Toasts->add('Boom', Type::Failure, TTL: 10.0, at: 10.0);

      yield assert(
         assertion: count($Toasts->queue) === 2
            && $Toasts->queue[0]['until'] === 13.0
            && $Toasts->queue[1]['until'] === 20.0,
         description: 'Toasts enqueue with default and explicit lifetimes'
      );

      // @ Expiry — pure queue mutation on the injected clock
      $Toasts->expire(at: 11.0);

      yield assert(
         assertion: count($Toasts->queue) === 2,
         description: 'Alive toasts survive an early expiry tick'
      );

      $Toasts->expire(at: 14.0);

      yield assert(
         assertion: count($Toasts->queue) === 1
            && $Toasts->queue[0]['message'] === 'Boom',
         description: 'The default-lifetime toast dies at its deadline; the longer one lives'
      );

      // @ RETURN mode reflects the queue
      $returned = (string) $Toasts->render(Toasts::RETURN_OUTPUT, at: 14.0);

      yield assert(
         assertion: str_contains($returned, 'Boom') === true
            && str_contains($returned, 'Saved') === false,
         description: 'RETURN mode renders only the alive toasts'
      );

      // @ Visible cap — the limit hides the oldest
      $Toasts->add('One', at: 14.0);
      $Toasts->add('Two', at: 14.0);
      $Toasts->add('Three', at: 14.0);

      $returned = (string) $Toasts->render(Toasts::RETURN_OUTPUT, at: 14.0);

      yield assert(
         assertion: count($Toasts->queue) === 4
            && str_contains($returned, 'Boom') === false
            && str_contains($returned, 'One') === true
            && str_contains($returned, 'Three') === true,
         description: 'The limit caps the visible stack at the newest toasts'
      );

      // @ Output side effects of add()
      rewind($Host->stream);
      $output = (string) stream_get_contents($Host->stream);

      if (BOOTGLY_TTY === true) {
         yield assert(
            assertion: $output === '',
            description: 'Interactive adds never paint — render() owns the screen'
         );
      }
      else {
         yield assert(
            assertion: str_contains($output, "[FAILURE] Boom\n") === true
               && str_contains($output, "[SUCCESS] Saved\n") === true,
            description: 'Non-interactive adds stream plain classified lines'
         );
      }
   }
);
