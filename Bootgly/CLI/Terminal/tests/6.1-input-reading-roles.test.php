<?php

namespace Bootgly\CLI\Terminal;


use function assert;
use function fopen;
use function fwrite;
use function rewind;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input\Roles;


return new Specification(
   description: 'It should run a single Client/Server role over an injected duplex channel',
   test: function () {
      // ! Client role: CAPI writes to the channel write stream
      $Input = new Input(fopen('php://memory', 'r+')); // @phpstan-ignore-line
      $channel = [fopen('php://memory', 'r+'), fopen('php://memory', 'r+')];
      $Input->role = Roles::Client;
      $Input->channel = $channel; // @phpstan-ignore-line

      $reads = null;
      $Input->reading(
         CAPI: function ($read, $write) use (&$reads) {
            $reads = $read;
            $write(data: 'hello from client');
         },
         SAPI: function ($reading) {
            // ? Must not run under the Client role
            yield assert(
               assertion: false,
               description: 'SAPI must not run when the role is Client'
            );
         }
      );

      rewind($channel[1]);
      yield assert(
         assertion: stream_get_contents($channel[1]) === 'hello from client',
         description: 'Client role wires CAPI writes into the channel write stream'
      );
      yield assert(
         assertion: $reads === [$Input, 'read'],
         description: 'Client role passes the Input read callable to CAPI'
      );

      // ! Server role: SAPI consumes the channel read stream until it closes
      $Input = new Input(fopen('php://memory', 'r+')); // @phpstan-ignore-line
      $channel = [fopen('php://memory', 'r+'), fopen('php://memory', 'r+')];
      fwrite($channel[0], 'hello from channel');
      rewind($channel[0]);
      $Input->role = Roles::Server;
      $Input->channel = $channel; // @phpstan-ignore-line

      $received = [];
      $Input->reading(
         CAPI: function ($read, $write) {
            // ? Must not run under the Server role
            yield assert(
               assertion: false,
               description: 'CAPI must not run when the role is Server'
            );
         },
         SAPI: function ($reading) use (&$received) {
            foreach ($reading(timeout: 1000) as $data) {
               $received[] = $data;

               if ($data === false) {
                  break;
               }
            }
         }
      );

      yield assert(
         assertion: $received === ['hello from channel', false],
         description: 'Server role yields channel data and finishes with false on close'
      );
   }
);
