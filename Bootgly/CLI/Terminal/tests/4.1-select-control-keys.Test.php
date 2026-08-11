<?php

namespace Bootgly\CLI\UI\Components;


use const BOOTGLY_TTY;
use const PHP_EOL;
use function assert;
use function fopen;
use function in_array;
use function is_bool;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Test(
   description: 'It should resolve BOOTGLY_TTY and finish Select control on both Enter byte forms',
   test: function () {
      // ! Select with in-memory streams
      $stream = fopen('php://memory', 'r+');
      $Input = new Input($stream); // @phpstan-ignore-line
      $Output = new Output('php://memory');
      $Select = new Select($Input, $Output);
      $Select->options = ['Single'];

      // @ Valid
      yield assert(
         assertion: is_bool(BOOTGLY_TTY),
         description: 'BOOTGLY_TTY resolved to a boolean: ' . (BOOTGLY_TTY ? 'interactive' : 'non-interactive')
      );
      yield assert(
         assertion: $Select->control(PHP_EOL) === false,
         description: 'Enter (line feed) finishes the Select control loop'
      );
      yield assert(
         assertion: $Select->control("\r") === false,
         description: 'Enter (carriage return — raw terminals without icrnl) finishes the Select control loop'
      );
      yield assert(
         assertion: $Select->control('') === true,
         description: 'Empty read keeps the Select control loop running'
      );
      yield assert(
         assertion: $Select->control("\e[5~") === true,
         description: 'Unmapped key keeps the Select control loop running'
      );

      // ! Locked options (display-only: never aimed, never selected)
      $Select = new Select($Input, $Output);
      $Select->multiple = true;
      $Select->options = ['Pinned', 'Real A', 'Real B'];
      $Select->locked = [0];

      // @ Space on the initial aim: the locked option never holds the aim
      $Select->control(' ');

      // @ Valid
      yield assert(
         assertion: in_array(1, $Select->selected) === true,
         description: 'Initial aim skips the locked option — Space selects the first unlocked one'
      );

      // @ Aiming up from the first unlocked option wraps over the locked one
      $Select->control("\e[A");
      $Select->control(' ');

      // @ Valid
      yield assert(
         assertion: in_array(2, $Select->selected) === true,
         description: 'Aim movement skips locked options (wraps to the last unlocked one)'
      );
      yield assert(
         assertion: in_array(0, $Select->selected) === false,
         description: 'Locked options never enter the selection'
      );
   }
);
