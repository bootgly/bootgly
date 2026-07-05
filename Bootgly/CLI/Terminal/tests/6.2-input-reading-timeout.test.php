<?php

namespace Bootgly\CLI\Terminal;


use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function assert;
use function count;
use function end;
use function fclose;
use function fopen;
use function fwrite;
use function in_array;
use function stream_socket_pair;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\CLI\Terminal\Input\Roles;


return new Specification(
   description: 'It should yield null on Server channel read timeouts (relay frame pacing)',
   test: function () {
      // ! Server role over a socket pair (honors stream read timeouts)
      $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      // ?
      if ($sockets === false) {
         yield assert(
            assertion: true,
            description: 'Skipped: stream_socket_pair unavailable'
         );

         return;
      }

      $Input = new Input(fopen('php://memory', 'r+')); // @phpstan-ignore-line
      $Input->role = Roles::Server;
      $Input->channel = [$sockets[0], $sockets[0]];

      $peer = $sockets[1];
      $received = [];

      // @
      $Input->reading(
         CAPI: function ($read, $write) {
            // ? Must not run under the Server role
            yield assert(
               assertion: false,
               description: 'CAPI must not run when the role is Server'
            );
         },
         SAPI: function ($reading) use (&$received, $peer) {
            foreach ($reading(512, 50000) as $data) {
               $received[] = $data;

               // @ First timeout: feed the channel, then close it
               if ($data === null && count($received) === 1) {
                  fwrite($peer, "UP\n");

                  continue;
               }
               if ($data === "UP\n") {
                  fclose($peer);

                  continue;
               }

               // ? Channel closed
               if ($data === false) {
                  break;
               }

               // ? Safety: never spin forever
               if (count($received) > 8) {
                  break;
               }
            }
         }
      );

      // @ Valid
      yield assert(
         assertion: $received !== [] && $received[0] === null,
         description: 'Idle channel yields null on the read timeout'
      );
      yield assert(
         assertion: in_array("UP\n", $received, true) === true,
         description: 'Data written after a timeout is delivered'
      );
      yield assert(
         assertion: end($received) === false,
         description: 'A closed channel yields false and ends the generator'
      );
   }
);
