<?php

namespace Bootgly\CLI\UI\Components;


use const PHP_EOL;
use function assert;
use function fopen;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Test(
   description: 'It should filter options incrementally with type-ahead keys',
   test: function () {
      // ! Select with in-memory streams
      $stream = fopen('php://memory', 'r+');
      $Input = new Input($stream); // @phpstan-ignore-line
      $Output = new Output('php://memory');

      $Select = new Select($Input, $Output);
      $Select->options = ['Alpha', 'Beta', 'Gamma', 'Delta'];

      // @ Typing accumulates the filter and aims the first match
      $Select->control('e');

      yield assert(
         assertion: $Select->filter === 'e',
         description: 'Printable keys accumulate in the filter'
      );

      $Select->control('l');

      // @ Valid — `el` only matches `Delta`
      yield assert(
         assertion: $Select->filter === 'el',
         description: 'The filter grows incrementally'
      );

      // @ Backspace pops the last filter byte; `Esc` clears it
      $Select->control("\x7F");

      yield assert(
         assertion: $Select->filter === 'e',
         description: 'Backspace pops the last filter byte'
      );

      $Select->control('l');
      $Select->control(PHP_EOL);

      // @ Valid — Enter confirms the aimed match (Delta, index 3)
      yield assert(
         assertion: $Select->selected === [3],
         description: 'Enter confirms the option aimed by the filter'
      );

      // @ `Esc` clears the filter
      $Select = new Select($Input, $Output);
      $Select->options = ['Alpha'];

      $Select->control('a');
      $Select->control("\e");

      yield assert(
         assertion: $Select->filter === '',
         description: 'Bare Escape clears the filter'
      );

      // @ Space never enters the filter (it selects)
      $Select->control(' ');

      yield assert(
         assertion: $Select->filter === '' && $Select->selected === [0],
         description: 'Space stays a selection key — never a filter byte'
      );
   }
);
