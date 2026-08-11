<?php

namespace Bootgly\CLI\UI\Components;


use const PHP_EOL;
use function assert;
use function fopen;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Test(
   description: 'It should confirm the aimed option on Enter when nothing is selected',
   test: function () {
      // ! Select with in-memory streams
      $stream = fopen('php://memory', 'r+');
      $Input = new Input($stream); // @phpstan-ignore-line
      $Output = new Output('php://memory');

      // @ Enter with an empty selection selects the aimed option
      $Select = new Select($Input, $Output);
      $Select->options = ['Alpha', 'Beta', 'Gamma'];

      $Select->control("\e[B"); // Aim: Beta

      // @ Valid
      yield assert(
         assertion: $Select->control(PHP_EOL) === false,
         description: 'Enter finishes the Select control loop'
      );
      yield assert(
         assertion: $Select->selected === [1],
         description: 'Enter with an empty selection confirms the aimed option'
      );

      // @ Enter with an explicit selection keeps it (aim is ignored)
      $Select = new Select($Input, $Output);
      $Select->options = ['Alpha', 'Beta'];

      $Select->control(' ');    // Select: Alpha
      $Select->control("\e[B"); // Aim: Beta
      $Select->control(PHP_EOL);

      // @ Valid
      yield assert(
         assertion: $Select->selected === [0],
         description: 'Enter with an explicit selection never overrides it with the aim'
      );

      // @ Enter with the aim on a locked option selects nothing
      $Select = new Select($Input, $Output);
      $Select->options = ['Pinned'];
      $Select->locked = [0];

      $Select->control(PHP_EOL);

      // @ Valid
      yield assert(
         assertion: $Select->selected === [],
         description: 'Locked options are never confirmed by Enter'
      );
   }
);
