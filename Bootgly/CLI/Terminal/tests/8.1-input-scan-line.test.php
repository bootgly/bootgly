<?php

namespace Bootgly\CLI\Terminal;


use function assert;
use function fopen;
use function fwrite;
use function rewind;

use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should scan lines from the Input stream until EOF',
   test: function () {
      // ! Input with in-memory stream
      $stream = fopen('php://memory', 'r+');
      fwrite($stream, "line1\nline2\rrest");
      rewind($stream);
      $Input = new Input($stream); // @phpstan-ignore-line

      // @ Valid
      yield assert(
         assertion: $Input->scan() === 'line1',
         description: 'Line terminated by LF is scanned without the terminator'
      );
      yield assert(
         assertion: $Input->scan() === 'line2',
         description: 'Line terminated by CR (raw terminals) is scanned without the terminator'
      );
      yield assert(
         assertion: $Input->scan() === 'rest',
         description: 'Last line without terminator is returned on EOF'
      );
      yield assert(
         assertion: $Input->scan() === false,
         description: 'Immediate EOF returns false'
      );
   }
);
