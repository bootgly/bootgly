<?php

namespace Bootgly\CLI\Terminal;


use function assert;
use function fopen;
use function fwrite;
use function rewind;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should self-echo and erase scanned input on emulated terminals',
   test: function () {
      // ! Input with in-memory streams (emulated TTY: self-echo enabled)
      $stream = fopen('php://memory', 'r+');
      fwrite($stream, "ab\x7Fc\né\x7Fok\n\x7Fz\n");
      rewind($stream);
      $echoed = fopen('php://memory', 'w+');
      $Input = new Input($stream); // @phpstan-ignore-line
      $Input->echo = true;
      $Input->output = $echoed; // @phpstan-ignore-line

      // @ Valid
      yield assert(
         assertion: $Input->scan() === 'ac',
         description: 'Backspace erases the last buffered character'
      );
      yield assert(
         assertion: $Input->scan() === 'ok',
         description: 'Backspace erases a whole multibyte UTF-8 character'
      );
      yield assert(
         assertion: $Input->scan() === 'z',
         description: 'Backspace on an empty buffer is ignored'
      );

      rewind($echoed);
      yield assert(
         assertion: stream_get_contents($echoed) === "ab\x08 \x08c\né\x08 \x08ok\nz\n",
         description: 'Input is echoed as typed: whole UTF-8 characters, erase sequences and newlines'
      );

      // ! Input with self-echo disabled (pipes / real TTYs)
      $stream = fopen('php://memory', 'r+');
      fwrite($stream, "xy\x7Fz\n");
      rewind($stream);
      $silent = fopen('php://memory', 'w+');
      $Input = new Input($stream); // @phpstan-ignore-line
      $Input->echo = false;
      $Input->output = $silent; // @phpstan-ignore-line

      yield assert(
         assertion: $Input->scan() === 'xz',
         description: 'Erase keys still edit the buffer with self-echo disabled'
      );

      rewind($silent);
      yield assert(
         assertion: stream_get_contents($silent) === '',
         description: 'Nothing is echoed with self-echo disabled'
      );
   }
);
