<?php

namespace Bootgly\CLI\UX;


use const BOOTGLY_TTY;
use function assert;
use function fopen;
use function rewind;
use function str_contains;
use function stream_get_contents;
use function strlen;
use function substr;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should switch by ordinal or label with wrap-around cycling',
   test: function () {
      $stream = fopen('php://memory', 'r+');
      $Input = new Input($stream); // @phpstan-ignore-line
      $Host = new Output('php://memory');

      $read = static function () use ($Host): string {
         rewind($Host->stream);

         return (string) stream_get_contents($Host->stream);
      };

      $Tabs = new Tabs($Input, $Host);
      $Tabs->width = 12;
      $Tabs->height = 4;

      $Log = $Tabs->add('Log');
      $CPU = $Tabs->add('CPU');
      $Board = $Tabs->add('Table');

      // @ Absolute switching — ordinal and label
      $Tabs->switch(2);

      yield assert(
         assertion: $Tabs->tab === 2 && $Tabs->Active === $CPU,
         description: 'Switching by 1-based ordinal activates the tab'
      );

      $Tabs->switch('Log');

      yield assert(
         assertion: $Tabs->tab === 1 && $Tabs->Active === $Log,
         description: 'Switching by label activates the tab'
      );

      // @ Invalid targets are silent no-ops
      $Tabs->switch('nope');
      $Tabs->switch(0);
      $Tabs->switch(9);

      yield assert(
         assertion: $Tabs->tab === 1,
         description: 'Unknown labels and out-of-range ordinals never move the activation'
      );

      // @ Numeric-string labels — PHP casts them to int keys; switching still works
      $Ports = new Tabs($Input, $Host);
      $Ports->add('Log');
      $Ports->add('8080');
      $Ports->switch('8080');

      yield assert(
         assertion: $Ports->tab === 2,
         description: 'Numeric-string labels stay reachable by label'
      );

      // @ The inherited $render property pins RETURN mode (Grid precedent)
      $Tabs->render = Tabs::RETURN_OUTPUT;
      $returned = $Tabs->render();
      $Tabs->render = Tabs::WRITE_OUTPUT;

      yield assert(
         assertion: $returned !== null && str_contains((string) $returned, '┌') === true,
         description: 'The render property returns the rectangle instead of writing'
      );

      // @ Cycling wraps around both ends
      $Tabs->cycle(-1);

      yield assert(
         assertion: $Tabs->tab === 3 && $Tabs->Active === $Board,
         description: 'Cycling backwards from the first tab wraps to the last'
      );

      $Tabs->cycle();

      yield assert(
         assertion: $Tabs->tab === 1,
         description: 'Cycling defaults to one tab forward, wrapping to the first'
      );

      // @ Paint behavior around switches
      $Tabs->render();
      $painted = strlen($read());

      if (BOOTGLY_TTY === true) {
         // ? Re-activating the current tab repaints nothing
         $Tabs->switch(1);
         $Tabs->render();

         yield assert(
            assertion: strlen($read()) === $painted,
            description: 'Re-activating the current tab emits zero bytes'
         );

         // ? A real switch invalidates — the full rectangle repaints
         $Tabs->switch(2);
         $Tabs->render();

         $delta = substr($read(), $painted);

         yield assert(
            assertion: str_contains($delta, "\e[1;1H") === true
               && str_contains($delta, "\e[2;1H") === true
               && str_contains($delta, "\e[3;1H") === true
               && str_contains($delta, "\e[4;1H") === true,
            description: 'A real switch repaints every rectangle row at its anchor'
         );
      }
      else {
         // ? Non-interactive output writes plainly on every render
         $Tabs->switch(2);
         $Tabs->render();

         yield assert(
            assertion: strlen($read()) > $painted
               && str_contains($read(), "\e[1;1H") === false,
            description: 'Non-interactive switches write plainly without cursor anchors'
         );
      }
   }
);
