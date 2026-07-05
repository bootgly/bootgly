<?php

namespace Bootgly\CLI\UI\Components;


use const BOOTGLY_TTY;
use const PHP_EOL;
use function assert;
use function fopen;
use function in_array;
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

      // ! Locked options (display-only: never aimed, never selected)
      $Menu = new Menu($Input, $Output);
      $Options = $Menu->Items->Options;
      $Options->Selection::Multiple->set();

      $Options->add(label: 'Pinned', locked: true);
      $Options->add(label: 'Real A');
      $Options->add(label: 'Real B');

      // @ Space on the initial aim: the locked option never holds the aim
      $Options->control(' ');

      // @ Valid
      yield assert(
         assertion: in_array(1, $Options::$selected[0]) === true,
         description: 'Initial aim skips the locked option — Space selects the first unlocked one'
      );

      // @ Aiming up from the first unlocked option wraps over the locked one
      $Options->control("\e[A");
      $Options->control(' ');

      // @ Valid
      yield assert(
         assertion: in_array(2, $Options::$selected[0]) === true,
         description: 'Aim movement skips locked options (wraps to the last unlocked one)'
      );
      yield assert(
         assertion: in_array(0, $Options::$selected[0]) === false,
         description: 'Locked options never enter the selection'
      );
   }
);
