<?php

namespace Bootgly\CLI\Terminal;


use function assert;
use function fopen;
use function fwrite;
use function rewind;
use function str_contains;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should self-echo the mask per completed character in scan(mask)',
   test: function () {
      // ! Input with in-memory streams (self-echo redirected to memory)
      $stream = fopen('php://memory', 'r+');
      fwrite($stream, "abc\x7Fd\n");
      rewind($stream);

      $echo = fopen('php://memory', 'r+');

      $Input = new Input($stream); // @phpstan-ignore-line
      $Input->echo = true;
      $Input->output = $echo; // @phpstan-ignore-line

      // @ Scan with mask
      $line = $Input->scan(mask: '*');

      // @ Valid
      yield assert(
         assertion: $line === 'abd',
         description: 'Erase keys edit the buffer — the mask never leaks into the value'
      );

      rewind($echo);
      $echoed = (string) stream_get_contents($echo);

      yield assert(
         assertion: $echoed === "***\x08 \x08*\n",
         description: 'Each completed character echoes the mask; Backspace erases one mask'
      );
      yield assert(
         assertion: str_contains($echoed, 'a') === false,
         description: 'Typed characters are never echoed when a mask is set'
      );

      // ! Mask off keeps the original self-echo
      $stream = fopen('php://memory', 'r+');
      fwrite($stream, "plain\n");
      rewind($stream);

      $echo = fopen('php://memory', 'r+');

      $Input = new Input($stream); // @phpstan-ignore-line
      $Input->echo = true;
      $Input->output = $echo; // @phpstan-ignore-line

      // @
      $line = $Input->scan();

      rewind($echo);
      $echoed = (string) stream_get_contents($echo);

      // @ Valid
      yield assert(
         assertion: $line === 'plain' && $echoed === "plain\n",
         description: 'Without a mask, input is echoed back as typed'
      );
   }
);
