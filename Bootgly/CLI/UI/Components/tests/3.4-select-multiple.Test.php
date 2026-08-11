<?php

namespace Bootgly\CLI\UI\Components;


use const PHP_EOL;
use function assert;
use function fopen;
use function str_contains;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Test(
   description: 'It should toggle a multiple selection with Space and swap a single one',
   test: function () {
      // ! Select with in-memory streams
      $stream = fopen('php://memory', 'r+');
      $Input = new Input($stream); // @phpstan-ignore-line
      $Output = new Output('php://memory');

      // @ Multiple: Space accumulates; re-Space deselects
      $Select = new Select($Input, $Output);
      $Select->multiple = true;
      $Select->options = ['Alpha', 'Beta', 'Gamma'];

      $Select->control(' ');    // Select: Alpha
      $Select->control("\e[B");
      $Select->control("\e[B");
      $Select->control(' ');    // Select: Gamma

      // @ Valid
      yield assert(
         assertion: $Select->selected === [0, 2],
         description: 'Space accumulates the selection in multiple mode'
      );

      $Select->control(' ');    // Deselect: Gamma

      yield assert(
         assertion: $Select->selected === [0],
         description: 'Space on a selected option deselects it'
      );

      // @ Enter keeps the explicit multiple selection
      yield assert(
         assertion: $Select->control(PHP_EOL) === false && $Select->selected === [0],
         description: 'Enter confirms the explicit multiple selection as-is'
      );

      // @ Checkbox marks paint the multiple mode
      $frame = (string) $Select->render(Select::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($frame, '◼ Alpha') === true && str_contains($frame, '◻ Beta') === true,
         description: 'Multiple mode marks options with ◼/◻ checkboxes'
      );

      // @ Single: Space swaps the one selected index
      $Select = new Select($Input, $Output);
      $Select->options = ['Alpha', 'Beta'];

      $Select->control(' ');    // Select: Alpha
      $Select->control("\e[B");
      $Select->control(' ');    // Select: Beta (swap)

      // @ Valid
      yield assert(
         assertion: $Select->selected === [1],
         description: 'Single mode swaps the selection instead of accumulating'
      );

      // @ Radio marks paint the single mode
      $frame = (string) $Select->render(Select::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($frame, '● Beta') === true && str_contains($frame, '○ Alpha') === true,
         description: 'Single mode marks options with ●/○ radios'
      );
   }
);
