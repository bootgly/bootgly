<?php

namespace Bootgly\CLI\UX\Components;


use const BOOTGLY_TTY;
use function assert;
use function fopen;
use function fwrite;
use function preg_match;
use function rewind;
use function str_contains;
use function stream_get_contents;
use function strpos;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should re-ask invalid field answers with the field Validator',
   test: function () {
      // ! Form with in-memory streams (invalid answer, then a valid one; Enter for the summary)
      $stream = fopen('php://memory', 'r+');
      fwrite($stream, "bad name\nGoodName\n\n");
      rewind($stream);

      $Input = new Input($stream); // @phpstan-ignore-line
      $Output = new Output('php://memory');

      $Form = new Form($Input, $Output);

      $Form->add(
         'Name',
         required: true,
         Validator: static function (string $answer): true|string {
            // ?:
            if (preg_match('#^[A-Z][A-Za-z0-9_-]*$#', $answer) !== 1) {
               return 'Invalid name: start with an uppercase letter.';
            }

            // :
            return true;
         }
      );

      // @
      $answers = $Form->ask();

      // @ Valid
      yield assert(
         assertion: $answers['Name'] === 'GoodName',
         description: 'Invalid answers re-ask until the field Validator accepts'
      );

      rewind($Output->stream);
      $output = (string) stream_get_contents($Output->stream);

      yield assert(
         assertion: str_contains($output, 'Invalid name') === true,
         description: 'The Validator error message is rendered as an Alert'
      );

      if (BOOTGLY_TTY === true) {
         // @ Interactive extras — the alert renders BELOW the fieldset frame and
         //   the frame turns green while the typed value passes the Validator
         yield assert(
            assertion: strpos($output, 'Invalid name') > strpos($output, '┘'),
            description: 'The validation alert renders below the fieldset frame'
         );

         yield assert(
            assertion: str_contains($output, "\e[92m") === true,
            description: 'Valid values paint the fieldset line green'
         );
      }
   }
);
