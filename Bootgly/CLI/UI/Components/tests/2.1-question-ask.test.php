<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function fopen;
use function fwrite;
use function rewind;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should ask questions with defaults, required re-asking and EOF fallback',
   test: function () {
      // ! Question with in-memory streams
      $stream = fopen('php://memory', 'r+');
      fwrite($stream, "\n\nanswered\n");
      rewind($stream);
      $Input = new Input($stream); // @phpstan-ignore-line
      $Output = new Output('php://memory');

      // @ Default on empty answer
      $Question = new Question($Input, $Output);
      $Question->prompt = 'Version';
      $Question->default = '1.0.0';

      yield assert(
         assertion: $Question->ask() === '1.0.0',
         description: 'Empty answer assumes the default'
      );

      // @ Required without default re-asks until answered
      $Question = new Question($Input, $Output);
      $Question->prompt = 'Name';
      $Question->required = true;

      yield assert(
         assertion: $Question->ask() === 'answered',
         description: 'Required question re-asks on empty answer until answered'
      );
      yield assert(
         assertion: $Question->attempt === 2,
         description: 'Attempt metadata counts the re-ask'
      );

      // @ EOF assumes the default
      $Question = new Question($Input, $Output);
      $Question->prompt = 'Author';
      $Question->default = 'anonymous';

      yield assert(
         assertion: $Question->ask() === 'anonymous',
         description: 'EOF assumes the default'
      );
   }
);
