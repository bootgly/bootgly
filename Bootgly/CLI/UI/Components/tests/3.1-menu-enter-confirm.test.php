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
   description: 'It should confirm the aimed option on Enter when nothing is selected',
   test: function () {
      // ! Menu with in-memory streams
      $stream = fopen('php://memory', 'r+');
      $Input = new Input($stream); // @phpstan-ignore-line
      $Output = new Output('php://memory');

      // @ Enter with an empty selection selects the aimed option
      $Menu = new Menu($Input, $Output);
      $Options = $Menu->Items->Options;
      $Options->Selection::Unique->set();

      $Options->add(label: 'Alpha');
      $Options->add(label: 'Beta');
      $Options->add(label: 'Gamma');

      $Options->control("\e[B"); // Aim: Beta

      // @ Valid
      yield assert(
         assertion: $Options->control(PHP_EOL) === false,
         description: 'Enter finishes the Menu control loop'
      );
      yield assert(
         assertion: in_array(1, $Options::$selected[0]) === true,
         description: 'Enter with an empty selection confirms the aimed option'
      );

      // @ Enter with an explicit selection keeps it (aim is ignored)
      $Menu = new Menu($Input, $Output);
      $Options = $Menu->Items->Options;
      $Options->Selection::Unique->set();

      $Options->add(label: 'Alpha');
      $Options->add(label: 'Beta');

      $Options->control(' ');    // Select: Alpha
      $Options->control("\e[B"); // Aim: Beta
      $Options->control(PHP_EOL);

      // @ Valid
      yield assert(
         assertion: in_array(0, $Options::$selected[0]) === true
            && in_array(1, $Options::$selected[0]) === false,
         description: 'Enter with an explicit selection never overrides it with the aim'
      );

      // @ Enter with the aim on a locked option selects nothing
      $Menu = new Menu($Input, $Output);
      $Options = $Menu->Items->Options;
      $Options->Selection::Unique->set();

      $Options->add(label: 'Pinned', locked: true);

      $Options->control(PHP_EOL);

      // @ Valid
      yield assert(
         assertion: $Options::$selected[0] === [],
         description: 'Locked options are never confirmed by Enter'
      );
   }
);
