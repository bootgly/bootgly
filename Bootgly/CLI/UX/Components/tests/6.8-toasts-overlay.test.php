<?php

namespace Bootgly\CLI\UX\Components;


use function array_keys;
use function assert;
use function count;
use function mb_strlen;
use function preg_replace;
use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Alert\Type;


return new Specification(
   description: 'It should compose the stack as absolute rows for frame-composing hosts',
   test: function () {
      Terminal::$columns = 80;
      Terminal::$lines = 24;

      $Host = new Output('php://memory');
      $Toasts = new Toasts($Host);

      // @ Empty queue composes nothing
      yield assert(
         assertion: $Toasts->overlay(at: 10.0) === [],
         description: 'An empty queue overlays no rows'
      );

      // @ One toast — a 3-row box anchored at the top-right
      $Toasts->add('Saved', Type::Success, at: 10.0);
      $rows = $Toasts->overlay(at: 11.0);
      $strip = static fn (string $row): string
         => (string) preg_replace('/\e\[[0-9;]*m/', '', $row);

      yield assert(
         assertion: count($rows) === 3
            && array_keys($rows) === [1, 2, 3],
         description: 'A toast composes its box height at 1-based screen rows'
      );

      yield assert(
         assertion: str_contains($strip($rows[2]), 'Saved') === true,
         description: 'The box body row carries the message'
      );

      // @ Right-aligned: the padded row ends exactly at the terminal edge
      yield assert(
         assertion: mb_strlen($strip($rows[1])) === 80,
         description: 'The left pad anchors the box flush to the right edge'
      );

      // @ Overlay expires on the injected clock — pure, no screen bookkeeping
      yield assert(
         assertion: $Toasts->overlay(at: 14.0) === [],
         description: 'Expired toasts leave the overlay'
      );

      // @ Stacking — the second box lands on the next slot rows
      $Toasts->add('One', Type::Default, TTL: 100.0, at: 20.0);
      $Toasts->add('Two', Type::Attention, TTL: 100.0, at: 20.0);
      $rows = $Toasts->overlay(at: 21.0);

      yield assert(
         assertion: count($rows) === 6
            && array_keys($rows) === [1, 2, 3, 4, 5, 6]
            && str_contains($strip($rows[2]), 'One') === true
            && str_contains($strip($rows[5]), 'Two') === true,
         description: 'Stacked boxes compose consecutive slot rows'
      );
   }
);
