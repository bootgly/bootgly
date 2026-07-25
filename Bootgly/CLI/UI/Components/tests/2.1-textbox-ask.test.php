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
      // ! Textbox with in-memory streams
      $stream = fopen('php://memory', 'r+');
      fwrite($stream, "\n\nanswered\n");
      rewind($stream);
      $Input = new Input($stream); // @phpstan-ignore-line
      $Output = new Output('php://memory');

      // @ Default on empty answer
      $Textbox = new Textbox($Input, $Output);
      $Textbox->prompt = 'Version';
      $Textbox->default = '1.0.0';

      yield assert(
         assertion: $Textbox->ask() === '1.0.0',
         description: 'Empty answer assumes the default'
      );

      // @ Required without default re-asks until answered
      $Textbox = new Textbox($Input, $Output);
      $Textbox->prompt = 'Name';
      $Textbox->required = true;

      yield assert(
         assertion: $Textbox->ask() === 'answered',
         description: 'Required question re-asks on empty answer until answered'
      );
      yield assert(
         assertion: $Textbox->attempt === 2,
         description: 'Attempt metadata counts the re-ask'
      );

      // @ EOF assumes the default
      $Textbox = new Textbox($Input, $Output);
      $Textbox->prompt = 'Author';
      $Textbox->default = 'anonymous';

      yield assert(
         assertion: $Textbox->ask() === 'anonymous',
         description: 'EOF assumes the default'
      );
   }
);
