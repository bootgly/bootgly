<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test;


// ? Wire-guard control (green before and after the HCLI-11/HCLI-3 fix) — a
//   Content-Length body over maxResponseBytes fails with code 0 'Response
//   Too Large' via the wire-byte counter, independent of the chunked
//   decoder's cap. Pins the guard through the ceiling rewiring — the first
//   client test to cover maxResponseBytes at all.
return new Test(
   description: 'It should fail a Content-Length body over maxResponseBytes',

   response: function () { return ''; },
   request: function () { return new Response; },

   responses: [
      function (): string {
         $body = str_repeat('W', 100000);

         return "HTTP/1.1 200 OK\r\nContent-Type: application/octet-stream\r\nContent-Length: 100000\r\nConnection: close\r\n\r\n{$body}";
      },
      function (): string {
         return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 5\r\nConnection: close\r\n\r\nafter";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client): Response {
         $Client->maxResponseBytes = 65536;
         $Client->timeout = 5; // ? Bound any unexpected death mode
         return $Client->request(method: 'GET', URI: '/overflow/wire-cap');
      },
      function (HTTP_Client_CLI $Client): Response {
         $Client->maxResponseBytes = 0; // @ Restore default (unbounded)
         $Response = $Client->request(method: 'GET', URI: '/overflow/wire-after');
         $Client->timeout = 30; // @ Restore default
         return $Response;
      },
   ],

   test: function (Response $Response1, Response $Response2) {
      yield assert(
         assertion: $Response1->code === 0 && $Response1->status === 'Response Too Large',
         description: "the wire guard fails the oversize body: code {$Response1->code} ('{$Response1->status}')"
      );
      yield assert(
         assertion: $Response2->code === 200 && $Response2->Body->raw === 'after',
         description: "the client recovers on the next request: {$Response2->code} '{$Response2->Body->raw}'"
      );
   }
);
