<?php

namespace Bootgly\CLI\UI\Components;


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
   description: 'It should validate answers with a Validator Closure and bounded attempts',
   test: function () {
      // ! Question with in-memory streams
      $stream = fopen('php://memory', 'r+');
      fwrite($stream, "bad name\nGoodName\nstill bad\nworse\n");
      rewind($stream);
      $Input = new Input($stream); // @phpstan-ignore-line
      $Output = new Output('php://memory');

      $Validator = static function (string $answer): true|string {
         // ?:
         if (preg_match('#^[A-Z][A-Za-z0-9_-]*$#', $answer) !== 1) {
            return 'Invalid name: use letters, numbers, `_` or `-`, starting uppercase.';
         }

         // :
         return true;
      };

      // @ Invalid answer re-asks until valid
      $Question = new Question($Input, $Output);
      $Question->prompt = 'Name';
      $Question->required = true;
      $Question->Validator = $Validator;

      yield assert(
         assertion: $Question->ask() === 'GoodName',
         description: 'Invalid answer re-asks until the Validator accepts'
      );
      yield assert(
         assertion: $Question->attempt === 2,
         description: 'Attempt metadata counts the rejected answer'
      );

      rewind($Output->stream);
      $output = (string) stream_get_contents($Output->stream);

      yield assert(
         assertion: str_contains($output, 'Invalid name') === true,
         description: 'Validator error message is rendered as an Alert'
      );

      // @ Exhausted attempts assume the default
      $Question = new Question($Input, $Output);
      $Question->prompt = 'Name';
      $Question->default = 'Fallback';
      $Question->attempts = 2;
      $Question->Validator = $Validator;

      yield assert(
         assertion: $Question->ask() === 'Fallback',
         description: 'Exhausted attempts assume the default'
      );
   }
);
