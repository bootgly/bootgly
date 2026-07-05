<?php

namespace Bootgly\CLI\UI\Components;


use const PHP_EOL;
use function assert;
use function fopen;
use function in_array;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should filter options incrementally with type-ahead keys',
   test: function () {
      // ! Menu with in-memory streams
      $stream = fopen('php://memory', 'r+');
      $Input = new Input($stream); // @phpstan-ignore-line
      $Output = new Output('php://memory');

      $Menu = new Menu($Input, $Output);
      $Options = $Menu->Items->Options;
      $Options->Selection::Unique->set();

      $Options->add(label: 'Alpha');
      $Options->add(label: 'Beta');
      $Options->add(label: 'Gamma');
      $Options->add(label: 'Delta');

      // @ Typing accumulates the filter and aims the first match
      $Options->control('e');

      yield assert(
         assertion: $Options->filter === 'e',
         description: 'Printable keys accumulate in the filter'
      );

      $Options->control('l');

      // @ Valid — `el` only matches `Delta`
      yield assert(
         assertion: $Options->filter === 'el',
         description: 'The filter grows incrementally'
      );

      // @ Backspace pops the last filter byte; `Esc` clears it
      $Options->control("\x7F");

      yield assert(
         assertion: $Options->filter === 'e',
         description: 'Backspace pops the last filter byte'
      );

      $Options->control('l');
      $Options->control(PHP_EOL);

      // @ Valid — Enter confirms the aimed match (Delta, index 3)
      yield assert(
         assertion: in_array(3, $Options::$selected[0]) === true,
         description: 'Enter confirms the option aimed by the filter'
      );

      // @ `Esc` clears the filter
      $Menu = new Menu($Input, $Output);
      $Options = $Menu->Items->Options;

      $Options->add(label: 'Alpha');
      $Options->control('a');
      $Options->control("\e");

      yield assert(
         assertion: $Options->filter === '',
         description: 'Bare Escape clears the filter'
      );

      // @ Space never enters the filter (it selects)
      $Options->control(' ');

      yield assert(
         assertion: $Options->filter === '' && in_array(0, $Options::$selected[0]) === true,
         description: 'Space stays a selection key — never a filter byte'
      );
   }
);
