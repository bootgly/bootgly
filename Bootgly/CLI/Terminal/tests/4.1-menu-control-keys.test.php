<?php

namespace Bootgly\CLI\UI\Components;


use const BOOTGLY_TTY;
use const PHP_EOL;
use function assert;
use function fopen;
use function is_bool;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should resolve BOOTGLY_TTY and finish Menu control on both Enter byte forms',
   test: function () {
      // ! Menu with in-memory streams
      $stream = fopen('php://memory', 'r+');
      $Input = new Input($stream); // @phpstan-ignore-line
      $Output = new Output('php://memory');
      $Menu = new Menu($Input, $Output);
      $Options = $Menu->Items->Options;

      // @ Valid
      yield assert(
         assertion: is_bool(BOOTGLY_TTY),
         description: 'BOOTGLY_TTY resolved to a boolean: ' . (BOOTGLY_TTY ? 'interactive' : 'non-interactive')
      );
      yield assert(
         assertion: $Options->control(PHP_EOL) === false,
         description: 'Enter (line feed) finishes the Menu control loop'
      );
      yield assert(
         assertion: $Options->control("\r") === false,
         description: 'Enter (carriage return — raw terminals without icrnl) finishes the Menu control loop'
      );
      yield assert(
         assertion: $Options->control('') === true,
         description: 'Empty read keeps the Menu control loop running'
      );
      yield assert(
         assertion: $Options->control('x') === true,
         description: 'Unmapped key keeps the Menu control loop running'
      );
   }
);
