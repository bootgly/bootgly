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
   description: 'It should prompt for raw answers with defaults on empty answer and EOF',
   test: function () {
      // ! Dialog with in-memory streams
      $stream = fopen('php://memory', 'r+');
      fwrite($stream, "  MyApp  \n\n");
      rewind($stream);
      $Input = new Input($stream); // @phpstan-ignore-line
      $Output = new Output('php://memory');
      $Dialog = new Dialog($Input, $Output);

      // @ Valid
      yield assert(
         assertion: $Dialog->prompt('Project name') === 'MyApp',
         description: 'Answer is trimmed'
      );
      yield assert(
         assertion: $Dialog->prompt('Project name', default: 'App') === 'App',
         description: 'Empty answer assumes the default'
      );
      yield assert(
         assertion: $Dialog->prompt('Project name', default: 'App') === 'App'
            && $Dialog->answer === 'App',
         description: 'EOF assumes the default and sets the answer metadata'
      );
   }
);
