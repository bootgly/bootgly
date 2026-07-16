<?php

namespace Bootgly\CLI\Terminal;


use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function assert;
use function fclose;
use function fopen;
use function fwrite;
use function rewind;
use function stream_set_blocking;
use function stream_socket_pair;

use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should listen to assembled keystrokes from the Input stream',
   test: function () {
      // ! Input factory with in-memory stream pre-fed with bytes
      $make = static function (string $bytes): Input {
         $stream = fopen('php://memory', 'r+');
         fwrite($stream, $bytes);
         rewind($stream);

         // @phpstan-ignore-next-line
         return new Input($stream);
      };

      // @ Plain bytes
      $Input = $make('a');
      yield assert(
         assertion: $Input->listen() === 'a',
         description: 'Plain byte is returned as-is'
      );

      // @ CSI sequences
      $Input = $make("\e[B");
      yield assert(
         assertion: $Input->listen() === "\e[B",
         description: 'Arrow CSI sequence is assembled to its final byte'
      );

      $Input = $make("\e[1;5A");
      yield assert(
         assertion: $Input->listen() === "\e[1;5A",
         description: 'Multi-byte CSI sequence (modifier) reads until its final byte'
      );

      // @ SS3 sequences
      $Input = $make("\eOP");
      yield assert(
         assertion: $Input->listen() === "\eOP",
         description: 'SS3 sequence assembles exactly one final byte'
      );

      // @ Bare Escape at stream end — a read byte is a key even at EOF
      $Input = $make("\e");
      yield assert(
         assertion: $Input->listen() === "\e",
         description: 'Bare Escape at stream end returns the key, not a closed channel'
      );

      // @ UTF-8 continuation bytes
      $Input = $make('é');
      yield assert(
         assertion: $Input->listen() === 'é',
         description: '2-byte UTF-8 character is assembled from its lead byte'
      );

      // @ Sequential keys from one stream
      $Input = $make("\e[Ax");
      yield assert(
         assertion: $Input->listen() === "\e[A" && $Input->listen() === 'x',
         description: 'Keys are consumed one at a time without desync'
      );

      // @ Exhausted stream
      $Input = $make('');
      yield assert(
         assertion: $Input->listen() === false,
         description: 'Exhausted stream returns false (channel closed)'
      );

      // @ Drained but not EOF — an open socket pair with no pending data
      $pair = (array) stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($pair[0], false);
      // @phpstan-ignore-next-line
      $Input = new Input($pair[0]);

      yield assert(
         assertion: $Input->listen() === '',
         description: 'An open drained channel returns an empty string (keep polling)'
      );

      fwrite($pair[1], 'k');

      yield assert(
         assertion: $Input->listen() === 'k',
         description: 'Data arriving later on the drained channel is delivered'
      );

      fclose($pair[1]);

      yield assert(
         assertion: $Input->listen() === false,
         description: 'A closed peer turns the drained channel into EOF'
      );
   }
);
