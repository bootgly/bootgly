<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;


// ? Panel-found gap — 17.1-17.4 all use 'Connection: close' origins, so the
//   finer mutant that maps the overflow to a failed Response but DROPS the
//   `$Connection->close()` call survives them: the origin tears the socket
//   down anyway. Here the origin keeps the connection alive, so closing the
//   poisoned mid-stream socket is the CLIENT's job. Without it the reactor
//   never halts (nothing else ends the request's drain) and the call only
//   returns when the origin gives up on its own read — measured at ~60 s
//   under the mutant versus milliseconds with the close in place.
// ! Wall-clock of the FAILING leg — the response content is identical either
//   way, so latency is what discriminates the mutant.
$elapsed = null;

return new Specification(
   description: 'It should close a keep-alive connection on a declared oversize chunk',

   response: function () { return ''; },
   request: function () { return new Response; },

   keepAlive: true,
   responses: [
      function (): string {
         // @ Keep-alive headers (no `Connection: close`) + a chunk-size line
         //   declaring 100,000 bytes; the origin then waits on this same
         //   connection for the next request that will never come
         return "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n186a0\r\n";
      },
      function (): string {
         return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 5\r\nConnection: close\r\n\r\nafter";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client) use (&$elapsed): Response {
         $Client->maxResponseBytes = 65536;

         $start = hrtime(true);
         $Response = $Client->request(method: 'GET', URI: '/overflow/keepalive');
         $elapsed = (hrtime(true) - $start) / 1e9;

         return $Response;
      },
      function (HTTP_Client_CLI $Client): Response {
         $Client->maxResponseBytes = 0; // @ Restore default (unbounded)
         return $Client->request(method: 'GET', URI: '/overflow/keepalive-after');
      },
   ],

   test: function (Response $Response1, Response $Response2) use (&$elapsed) {
      yield assert(
         assertion: $Response1->code === 0 && $Response1->status === 'Response Too Large',
         description: "the declared oversize fails fast on keep-alive: code {$Response1->code} ('{$Response1->status}')"
      );
      yield assert(
         assertion: $elapsed !== null && $elapsed < 3.0,
         description: 'the failed request returns at once (' . round((float) $elapsed, 3) . ' s) — a socket left open holds the reactor until the origin gives up'
      );
      yield assert(
         assertion: $Response2->code === 200 && $Response2->Body->raw === 'after',
         description: "the client recovers on a fresh connection: {$Response2->code} '{$Response2->Body->raw}'"
      );
   }
);
