<?php

namespace Bootgly\CLI\Terminal\Reporting;


use function assert;
use function fopen;
use function rewind;
use function str_contains;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should write the mouse tracking escape sequences when reporting toggles',
   test: function () {
      // ! Mouse with in-memory streams
      $stream = fopen('php://memory', 'r+');
      $Input = new Input($stream); // @phpstan-ignore-line
      $Output = new Output('php://memory');
      $Mouse = new Mouse($Input, $Output);

      // @ Enable
      $Mouse->report(true);
      rewind($Output->stream);
      $written = (string) stream_get_contents($Output->stream);

      // @ Valid
      yield assert(
         assertion: str_contains($written, '?1003h'),
         description: 'Enabling turns on all-event tracking (?1003h)'
      );
      yield assert(
         assertion: str_contains($written, '?1006h'),
         description: 'Enabling sets the SGR extension mode (?1006h)'
      );

      // @ Disable
      $Mouse->report(false);
      rewind($Output->stream);
      $written = (string) stream_get_contents($Output->stream);

      yield assert(
         assertion: str_contains($written, '?1003l'),
         description: 'Disabling turns off all-event tracking (?1003l)'
      );
      yield assert(
         assertion: str_contains($written, '?1006l'),
         description: 'Disabling unsets the SGR extension mode (?1006l)'
      );
   }
);
