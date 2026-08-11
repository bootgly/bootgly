<?php

namespace Bootgly\CLI\UI\Base;


use function assert;
use function fseek;
use function ftell;
use function rewind;
use function str_contains;
use function stream_get_contents;
use function substr_count;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Base\Flyout\Placements;


return new Test(
   description: 'It should compose and paint a generic anchored overlay block',
   test: function () {
      $Output = new Output('php://memory');

      $Flyout = new Flyout($Output);

      // @ Closed while there is nothing to show
      yield assert(
         assertion: $Flyout->render(Flyout::RETURN_OUTPUT) === '' && $Flyout->height === 0,
         description: 'Empty content renders nothing and reports a height of 0'
      );

      // @ The borderless block is the content itself, one trailing break
      $Flyout->content = "one\ntwo";
      $block = (string) $Flyout->render(Flyout::RETURN_OUTPUT);

      yield assert(
         assertion: $block === "one\ntwo\n" && $Flyout->height === 2,
         description: 'Borderless content composes as-is and counts its rows'
      );

      // @ The bordered composition delegates the box to the Fieldset
      $Flyout->bordered = true;
      $Flyout->title = 'Commands';
      $block = (string) $Flyout->render(Flyout::RETURN_OUTPUT);

      yield assert(
         assertion: $Flyout->height === 4
            && substr_count($block, "\n") === 4
            && str_contains($block, '┌') === true
            && str_contains($block, '└') === true
            && str_contains($block, 'Commands') === true,
         description: 'Bordered content wraps in a titled box and counts its two border rows'
      );

      // @ Emptying the content closes the flyout again
      $Flyout->content = '';
      $Flyout->render(Flyout::RETURN_OUTPUT);

      yield assert(
         assertion: $Flyout->height === 0,
         description: 'Emptied content resets the height to 0'
      );

      // @ WRITE + Below paints under the anchor and returns to the anchor row
      $Output = new Output('php://memory');
      $Flyout = new Flyout($Output);
      $Flyout->content = "aa\nbb";
      $Flyout->render();

      rewind($Output->stream);
      $painted = (string) stream_get_contents($Output->stream);

      yield assert(
         assertion: $painted === "\n\e[1G\e[0Kaa\n\e[1G\e[0Kbb\e[2F",
         description: 'Below paints each row under the anchor (`\n` steps scroll-safe) and CPLs back'
      );

      // @ close() erases exactly the painted rows (Below)
      $Output = new Output('php://memory');
      $Flyout = new Flyout($Output);
      $Flyout->content = "aa\nbb";
      $Flyout->render();

      // ! Writes land at the stream pointer — mark it to read only what close() emits
      $offset = (int) ftell($Output->stream);

      $Flyout->close();

      fseek($Output->stream, $offset);
      $erased = (string) stream_get_contents($Output->stream);

      yield assert(
         assertion: $erased === "\e[1E\e[2K\e[1B\e[2K\e[1A\e[1F" && $Flyout->height === 0,
         description: 'close() steps below the anchor, clears the block rows and returns'
      );

      // @ A closed flyout has nothing to erase
      $Flyout->close();

      yield assert(
         assertion: stream_get_contents($Output->stream) === '',
         description: 'close() is a no-op while closed'
      );

      // @ WRITE + Above paints over the anchor (the host made room)
      $Output = new Output('php://memory');
      $Flyout = new Flyout($Output);
      $Flyout->placement = Placements::Above;
      $Flyout->content = "aa\nbb";
      $Flyout->render();

      rewind($Output->stream);
      $painted = (string) stream_get_contents($Output->stream);

      yield assert(
         assertion: $painted === "\e[2F\e[0Kaa\e[1E\e[0Kbb\e[1E",
         description: 'Above CPLs to the block top, paints row by row and lands on the anchor'
      );

      // @ close() erases the rows over the anchor (Above)
      $offset = (int) ftell($Output->stream);

      $Flyout->close();

      fseek($Output->stream, $offset);
      $erased = (string) stream_get_contents($Output->stream);

      yield assert(
         assertion: $erased === "\e[2F\e[2K\e[1B\e[2K\e[1A\e[2E" && $Flyout->height === 0,
         description: 'close() climbs to the block top, clears its rows and returns to the anchor'
      );
   }
);
