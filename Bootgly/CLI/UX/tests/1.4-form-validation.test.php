<?php

namespace Bootgly\CLI\UX;


use function assert;
use function fopen;
use function fwrite;
use function preg_match;
use function rewind;
use function str_contains;
use function stream_get_contents;

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
   }
);
