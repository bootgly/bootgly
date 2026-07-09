<?php

use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\WS_Server_CLI\Handshake;


return new Specification(
   description: 'It should build the HTTP fallback response for non-upgrade requests',
   test: function () {
      // @ serve() — plain HTTP 200 with media type, length and close
      $response = Handshake::serve('text/html; charset=UTF-8', '<html>client</html>');

      yield assert(
         assertion: str_contains($response, 'HTTP/1.1 200 OK') === true
            && str_contains($response, 'Content-Type: text/html; charset=UTF-8') === true
            && str_contains($response, 'Content-Length: 19') === true
            && str_contains($response, 'Connection: close') === true
            && str_contains($response, '<html>client</html>') === true,
         description: 'serve() builds a complete HTTP 200 wire response'
      );

      // @ deny(404) — the fallback miss answer
      yield assert(
         assertion: Handshake::deny(404) === "HTTP/1.1 404 Not Found\r\nConnection: close\r\n\r\n",
         description: 'deny(404) rejects with Not Found + close'
      );

      // @ check() — the upgrade-token probe used by the fallback branch
      yield assert(
         assertion: Handshake::check('keep-alive, Upgrade', 'upgrade') === true
            && Handshake::check('websocket', 'websocket') === true
            && Handshake::check('', 'websocket') === false,
         description: 'check() matches comma-separated tokens case-insensitively'
      );
   }
);
