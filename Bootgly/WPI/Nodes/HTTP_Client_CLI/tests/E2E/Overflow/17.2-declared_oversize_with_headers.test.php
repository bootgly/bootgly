<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;


// ? HCLI-11/HCLI-3 regression — when the chunk-size line arrives in the SAME
//   read as the headers, the immediate post-header decode must fail fast on a
//   declared chunk that would exceed maxResponseBytes, before any body byte
//   is downloaded. Before the fix the decoder waited for data the client
//   would never accept and the request died at EOF as 'Truncated Response'.
//   The second leg proves the client recovers on a fresh connection.
return new Specification(
   description: 'It should fail fast on a declared oversize chunk arriving with the headers',

   response: function () { return ''; },
   request: function () { return new Response; },

   responses: [
      function (): string {
         // @ Headers + a chunk-size line declaring 100,000 bytes — and nothing
         //   else: the origin closes without ever sending the chunk data
         return "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n186a0\r\n";
      },
      function (): string {
         return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 5\r\nConnection: close\r\n\r\nafter";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client): Response {
         $Client->maxResponseBytes = 65536;
         $Client->timeout = 5; // ? Bound any unexpected death mode
         return $Client->request(method: 'GET', URI: '/overflow/with-headers');
      },
      function (HTTP_Client_CLI $Client): Response {
         $Client->maxResponseBytes = 0; // @ Restore default (unbounded)
         $Response = $Client->request(method: 'GET', URI: '/overflow/after');
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
