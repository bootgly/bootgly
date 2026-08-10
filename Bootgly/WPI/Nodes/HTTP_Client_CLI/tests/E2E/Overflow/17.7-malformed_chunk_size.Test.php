<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test;


// ? HCLI-19 regression (malformed framing) — `hexdec('zz')` is 0, so a garbage
//   chunk-size line used to be read as the TERMINAL chunk: the response
//   completed as 200 OK carrying only the bytes decoded so far, with the rest
//   of the stream silently discarded. A malformed size line must fail the
//   response, and with a status that tells the truth about WHY — not the
//   size-limit status, which would be a different lie.
return new Test(
   description: 'It should fail a response whose chunk-size line is not hexadecimal',

   response: function () { return ''; },
   request: function () { return new Response; },

   responses: [
      function (): string {
         // @ A well-formed first chunk, then garbage where a size must be
         return "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n5\r\nhello\r\nzz\r\nworld\r\n0\r\n\r\n";
      },
      function (): string {
         return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 5\r\nConnection: close\r\n\r\nafter";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client): Response {
         $Client->timeout = 5; // ? Bound any unexpected death mode
         return $Client->request(method: 'GET', URI: '/overflow/malformed-size');
      },
      function (HTTP_Client_CLI $Client): Response {
         $Response = $Client->request(method: 'GET', URI: '/overflow/malformed-after');
         $Client->timeout = 30; // @ Restore default
         return $Response;
      },
   ],

   test: function (Response $Response1, Response $Response2) {
      yield assert(
         assertion: $Response1->code === 0 && $Response1->status === 'Invalid Chunked Encoding',
         description: "malformed framing fails truthfully: code {$Response1->code} ('{$Response1->status}')"
      );
      yield assert(
         assertion: $Response1->Body->raw !== 'hello',
         description: 'the partial body is not handed back as a completed response'
      );
      yield assert(
         assertion: $Response2->code === 200 && $Response2->Body->raw === 'after',
         description: "the client recovers on the next request: {$Response2->code} '{$Response2->Body->raw}'"
      );
   }
);
