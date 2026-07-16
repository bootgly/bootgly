<?php

namespace Bootgly\CLI\UX\Components;


use const BOOTGLY_TTY;
use function assert;
use function fopen;
use function fwrite;
use function rewind;
use function str_contains;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should confirm modally — y/n answer; Enter, Esc and EOF assume the default',
   test: function () {
      $make = static function (string $bytes): Dialog {
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, $bytes);
         rewind($stream);

         $Input = new Input($stream); // @phpstan-ignore-line
         $Output = new Output('php://memory');

         $Dialog = new Dialog($Input, $Output);
         $Dialog->centered = false;
         $Dialog->row = 1;
         $Dialog->column = 1;
         $Dialog->width = 40;
         $Dialog->height = 5;

         return $Dialog;
      };

      // @ Affirmative answer
      $Dialog = $make(BOOTGLY_TTY === true ? 'y' : "y\n");
      $confirmed = $Dialog->confirm('Deploy?', false);

      yield assert(
         assertion: $confirmed === true && $Dialog->confirmed === true,
         description: 'A `y` answer confirms against a false default'
      );

      rewind($Dialog->Output->stream);
      $output = (string) stream_get_contents($Dialog->Output->stream);

      yield assert(
         assertion: str_contains($output, 'Deploy?') === true
            && str_contains($output, '[y/N]') === true,
         description: 'The prompt and the keys hint reach the output'
      );

      // @ Negative answer
      $Dialog = $make(BOOTGLY_TTY === true ? 'n' : "n\n");

      yield assert(
         assertion: $Dialog->confirm('Deploy?', true) === false,
         description: 'A `n` answer denies against a true default'
      );

      // @ Enter assumes the default — the trailing `y` would flip the answer
      //   if Enter went unhandled, so the assertion is not satisfiable by the
      //   EOF fallback alone
      $Dialog = $make("\ny");

      yield assert(
         assertion: $Dialog->confirm('Deploy?', false) === false,
         description: 'Enter assumes the default before any later key'
      );

      // @ EOF assumes the default
      $Dialog = $make('');

      yield assert(
         assertion: $Dialog->confirm('Deploy?', true) === true
            && $Dialog->confirmed === true,
         description: 'EOF assumes the default'
      );

      if (BOOTGLY_TTY === true) {
         // @ Bare Esc assumes the default (interactive only — pipes have no Esc)
         $Dialog = $make("\e");

         yield assert(
            assertion: $Dialog->confirm('Deploy?', false) === false,
            description: 'A bare Esc assumes the default'
         );

         // @ The dialog restores its closed state after the interaction
         yield assert(
            assertion: $Dialog->opened === false,
            description: 'Standalone confirmations close the dialog on return'
         );

         // @ Nested variant — the caller's hosted body survives the interaction
         $Dialog = $make('y');
         $Dialog->Frame->Output->write("Hosted\n");
         $Dialog->open();

         $confirmed = $Dialog->confirm('Sure?', false);

         yield assert(
            assertion: $confirmed === true
               && $Dialog->opened === true
               && $Dialog->Frame->buffer === ['Hosted'],
            description: 'A nested confirm restores the hosted body and leaves the box open'
         );

         $Dialog->close();
      }
   }
);
