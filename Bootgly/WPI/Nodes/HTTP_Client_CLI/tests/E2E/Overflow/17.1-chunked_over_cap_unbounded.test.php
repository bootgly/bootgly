<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;


// ! Position-unique payload 8 bytes over the old hidden 10 MiB decoder cap:
//   1,310,721 8-byte blocks = 10,485,768 bytes (cap was 10,485,760).
$payload = '';
for ($index = 0; $index < 1310721; $index++) {
   $payload .= sprintf("%07d\n", $index);
}

// ? HCLI-11/HCLI-3 regression — with the default configuration
//   (maxResponseBytes = 0 = unbounded, as documented) a single chunk over the
//   old hidden 10 MiB cap was wiped and delivered as 200 OK with an EMPTY
//   body: the decoder returned the cap breach as a completion record and the
//   client trusted it. Unbounded must mean unbounded.
return new Specification(
   description: 'It should decode a chunked body over 10 MiB when unbounded',

   response: function () use ($payload): string {
      $size = dechex(strlen($payload));

      return "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nContent-Type: application/octet-stream\r\nConnection: close\r\n\r\n{$size}\r\n{$payload}\r\n0\r\n\r\n";
   },

   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request(
         method: 'GET',
         URI: '/chunked-over-cap'
      );
   },

   test: function (Response $Response) use ($payload): Generator {
      yield assert(
         assertion: $Response->code === 200,
         description: "an over-10-MiB chunked response completes: code {$Response->code} ({$Response->status})"
      );
      yield assert(
         assertion: strlen($Response->Body->raw) === 10485768,
         description: 'all 10,485,768 body bytes arrive (got ' . strlen($Response->Body->raw) . ')'
      );
      yield assert(
         assertion: $Response->Body->raw === $payload,
         description: 'the body is byte-exact — no wipe, no replay'
      );
   }
);
