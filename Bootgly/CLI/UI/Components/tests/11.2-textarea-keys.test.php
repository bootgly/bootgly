<?php

namespace Bootgly\CLI\UI\Components;


use const BOOTGLY_TTY;
use function assert;
use function fopen;
use function fwrite;
use function rewind;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Input\Keystrokes;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should assemble multi-byte keys whole (Delete, Ctrl+arrows) instead of truncating them',
   test: function () {
      // ! Sequences longer than 3 bytes — the ones a fixed `read(2)` window cuts
      $stream = fopen('php://memory', 'r+');
      fwrite($stream, Keystrokes::DELETE->value . Keystrokes::CTRL_UP->value);
      rewind($stream);

      $Input = new Input($stream); // @phpstan-ignore-line

      yield assert(
         assertion: $Input->listen() === "\e[3~",
         description: 'Delete arrives whole — a truncated `\e[3` would leak `~` as typed text'
      );

      yield assert(
         assertion: $Input->listen() === "\e[1;5A",
         description: 'Ctrl+Up arrives whole (6 bytes)'
      );

      // ! Handling — the editor acts on the assembled key
      $stream = fopen('php://memory', 'r+');
      $Input = new Input($stream); // @phpstan-ignore-line
      $Output = new Output('php://memory');

      $Textarea = new Textarea($Input, $Output);

      $Textarea->control('a');
      $Textarea->control('b');
      $Textarea->control(Keystrokes::HOME->value);
      $Textarea->control(Keystrokes::DELETE->value);

      yield assert(
         assertion: $Textarea->lines === ['b'] && $Textarea->column === 0,
         description: 'Delete erases the character under the cursor'
      );

      // ! End to end through the wait loop
      if (BOOTGLY_TTY === true) {
         $stream = fopen('php://memory', 'r+');
         fwrite(
            $stream,
            'ab' . Keystrokes::HOME->value . Keystrokes::DELETE->value . Keystrokes::CTRL_D->value
         );
         rewind($stream);

         $Input = new Input($stream); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         $Textarea = new Textarea($Input, $Output);

         yield assert(
            assertion: $Textarea->ask() === 'b',
            description: 'Interactive editing applies Delete instead of typing a stray `~`'
         );
      }
      else {
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, "one\ntwo\n");
         rewind($stream);

         $Input = new Input($stream); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         $Textarea = new Textarea($Input, $Output);

         yield assert(
            assertion: $Textarea->ask() === "one\ntwo",
            description: 'Non-interactive input keeps reading stdin lines'
         );
      }
   }
);
