<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test;


// ? HCLI-1 regression (blind chunk terminator) — RFC 9112 §7.1 frames a chunk
//   as `chunk-size [chunk-ext] CRLF chunk-data CRLF`, but the client advanced
//   past the trailing CRLF without ever reading it, so ANY two octets passed
//   for it. A stream a conformant parser rejects therefore completed as a
//   200, with the parse resuming at a boundary the origin chose rather than
//   the one the protocol defines. Here the origin terminates a 1-byte chunk
//   with `AA` and hides a whole fabricated response behind the terminal chunk.
//   Measured before the fix: the misframed stream completed as `200 OK`
//   carrying 'X'. The third assertion is a FORWARD guard, not a reproduction —
//   a sync request resets `pendingBuffer`, so the hidden response is discarded
//   today; it would land the day pipelined leftover is carried across pooled
//   requests.
return new Test(
   description: 'It should reject chunk-data that is not terminated by CRLF',

   response: function () { return ''; },
   request: function () { return new Response; },

   keepAlive: true,
   responses: [
      function (): string {
         return "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n"
            . "1\r\nX"   // @ chunk-size 1 + CRLF + the chunk-data byte
            . 'AA'       // ! where the terminating CRLF must be
            . "0\r\n\r\n"
            // ! Reachable only by a decoder that accepted the two octets above
            . "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nPWNED";
      },
      function (): string {
         return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 5\r\nConnection: close\r\n\r\nafter";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client): Response {
         $Client->timeout = 5; // ? Bound any unexpected death mode
         return $Client->request(method: 'GET', URI: '/overflow/blind-terminator');
      },
      function (HTTP_Client_CLI $Client): Response {
         $Response = $Client->request(method: 'GET', URI: '/overflow/blind-terminator-after');
         $Client->timeout = 30; // @ Restore default
         return $Response;
      },
   ],

   test: function (Response $Response1, Response $Response2) {
      yield assert(
         assertion: $Response1->code === 0 && $Response1->status === 'Invalid Chunked Encoding',
         description: "a non-CRLF chunk terminator fails the response: code {$Response1->code} ('{$Response1->status}')"
      );
      yield assert(
         assertion: $Response1->Body->raw !== 'X',
         description: 'the misframed chunk is not handed back as a completed body'
      );
      yield assert(
         assertion: $Response2->Body->raw === 'after',
         description: "the next pooled request still reads the real origin: '{$Response2->Body->raw}'"
      );
   }
);
