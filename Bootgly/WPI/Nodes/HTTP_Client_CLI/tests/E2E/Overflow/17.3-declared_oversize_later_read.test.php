<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;


// ? HCLI-11/HCLI-3 regression — sibling of 17.2 for the OTHER consumer
//   branch: here the chunk-size line arrives in a LATER read than the
//   headers, so the overflow surfaces from the parse-loop decode instead of
//   the immediate post-header decode. Both branches must map it to the same
//   'Response Too Large' failure.
return new Specification(
   description: 'It should fail fast on a declared oversize chunk arriving after the headers',

   response: function () { return ''; },
   request: function () { return new Response; },

   responses: [
      function (): Generator {
         // @ Write 1: headers alone — the chunked handoff happens with no data
         yield "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n";
         // @ Write 2: a chunk-size line declaring 100,000 bytes, then the
         //   origin closes without ever sending the chunk data
         yield "186a0\r\n";
      },
      function (): string {
         return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 5\r\nConnection: close\r\n\r\nafter";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client): Response {
         $Client->maxResponseBytes = 65536;
         $Client->timeout = 5; // ? Bound any unexpected death mode
         return $Client->request(method: 'GET', URI: '/overflow/later-read');
      },
      function (HTTP_Client_CLI $Client): Response {
         $Client->maxResponseBytes = 0; // @ Restore default (unbounded)
         $Response = $Client->request(method: 'GET', URI: '/overflow/later-after');
         $Client->timeout = 30; // @ Restore default
         return $Response;
      },
   ],

   test: function (Response $Response1, Response $Response2) {
      yield assert(
         assertion: $Response1->code === 0 && $Response1->status === 'Response Too Large',
         description: "the declared oversize fails fast: code {$Response1->code} ('{$Response1->status}')"
      );
      yield assert(
         assertion: $Response2->code === 200 && $Response2->Body->raw === 'after',
         description: "the client recovers on the next request: {$Response2->code} '{$Response2->Body->raw}'"
      );
   }
);
