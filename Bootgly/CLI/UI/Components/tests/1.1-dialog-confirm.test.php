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
   description: 'It should confirm yes/no answers with defaults on empty answer and EOF',
   test: function () {
      // ! Dialog with in-memory streams
      $stream = fopen('php://memory', 'r+');
      fwrite($stream, "y\n\nno\nYES\n");
      rewind($stream);
      $Input = new Input($stream); // @phpstan-ignore-line
      $Output = new Output('php://memory');
      $Dialog = new Dialog($Input, $Output);

      // @ Valid
      yield assert(
         assertion: $Dialog->confirm('Continue?') === true,
         description: 'Answer `y` confirms'
      );
      yield assert(
         assertion: $Dialog->confirm('Continue?', default: true) === true,
         description: 'Empty answer assumes the default (true)'
      );
      yield assert(
         assertion: $Dialog->confirm('Continue?', default: true) === false,
         description: 'Answer `no` refuses'
      );
      yield assert(
         assertion: $Dialog->confirm('Continue?') === true
            && $Dialog->confirmed === true,
         description: 'Case-insensitive `YES` confirms and sets the confirmed metadata'
      );
      yield assert(
         assertion: $Dialog->confirm('Continue?', default: true) === true,
         description: 'EOF assumes the default (true)'
      );
   }
);
