<?php

namespace Bootgly\CLI\Terminal;


use function assert;
use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input\Line;


return new Specification(
   description: 'It should render the visible slice with the virtual cursor and masks',
   test: function () {
      // @ Cursor cell renders inverse-video (a space at the end of the value)
      //   Raw SGR: Template style markers swallow adjacent spaces — a markup cursor cell vanishes
      $Line = new Line;
      $Line->feed('abc');

      $rendered = $Line->render();

      yield assert(
         assertion: str_contains($rendered, 'abc') === true && str_contains($rendered, "\e[7m \e[0m") === true,
         description: 'The cursor renders as an inverse-video trailing space at the end'
      );

      // @ Width truncation with ellipsis
      $Line->reset();
      $Line->width = 5;
      $Line->feed('abcdefghij'); // cursor at the end (10)

      $rendered = $Line->render();

      yield assert(
         assertion: str_contains($rendered, '…') === true && str_contains($rendered, 'abc') === false,
         description: 'Left-truncated values render the dim ellipsis and hide the head'
      );

      // @ Masked render never reveals the value
      $Secret = new Line;
      $Secret->mask = '•';
      $Secret->feed('hunter2');

      $rendered = $Secret->render();

      yield assert(
         assertion: str_contains($rendered, 'hunter2') === false && str_contains($rendered, '•••••••') === true,
         description: 'Masked lines render the mask repeated, never the value'
      );
   }
);
