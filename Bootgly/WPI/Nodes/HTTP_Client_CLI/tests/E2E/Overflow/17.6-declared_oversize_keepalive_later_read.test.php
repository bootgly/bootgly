<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;


// ? Sibling of 17.5 for the OTHER consumer branch: the chunk-size line
//   arrives in a LATER read than the headers, so the overflow surfaces from
//   the parse-loop block instead of the immediate post-header decode. Both
//   blocks own the same duty — closing the poisoned keep-alive socket — and
//   each needs its own pin, since a mutant can drop either `close()` alone.
// ! Wall-clock of the FAILING leg discriminates the mutant (~60 s vs ms).
$elapsed = null;

return new Specification(
   description: 'It should close a keep-alive connection when the oversize chunk is declared after the headers',

   response: function () { return ''; },
   request: function () { return new Response; },

   keepAlive: true,
   responses: [
      function (): Generator {
         // @ Write 1: keep-alive headers alone (no `Connection: close`)
         yield "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n";
         // @ Write 2: a chunk-size line declaring 100,000 bytes; the origin
         //   then waits on this same connection
         yield "186a0\r\n";
      },
      function (): string {
         return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 5\r\nConnection: close\r\n\r\nafter";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client) use (&$elapsed): Response {
         $Client->maxResponseBytes = 65536;

         $start = hrtime(true);
         $Response = $Client->request(method: 'GET', URI: '/overflow/keepalive-later');
         $elapsed = (hrtime(true) - $start) / 1e9;

         return $Response;
      },
      function (HTTP_Client_CLI $Client): Response {
         $Client->maxResponseBytes = 0; // @ Restore default (unbounded)
         return $Client->request(method: 'GET', URI: '/overflow/keepalive-later-after');
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
