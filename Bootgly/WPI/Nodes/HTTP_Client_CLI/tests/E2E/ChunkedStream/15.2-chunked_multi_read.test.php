<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;


// ! Position-unique payload: 17,500 8-byte blocks ("0000000\n".."0017499\n").
//   An interior replay of earlier bytes can never equal the expected stream —
//   str_repeat() content would let a replay slip through unnoticed.
$payload = '';
for ($index = 0; $index < 17500; $index++) {
   $payload .= sprintf("%07d\n", $index);
}

// ? HCLI-1 regression — the client reads at most 65535 bytes per event, so a
//   140,000-byte chunked body written in ONE fwrite still spans 3 reads.
//   Before the fix the re-fed accumulation corrupted an interior window (the
//   audit measured divergence at byte ~127928) while the response completed
//   as 200 OK with the correct total length.
return new Specification(
   description: 'It should decode a chunked body larger than one read without replaying bytes',

   response: function () use ($payload): string {
      $size = dechex(strlen($payload));

      return "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nContent-Type: application/octet-stream\r\nConnection: close\r\n\r\n{$size}\r\n{$payload}\r\n0\r\n\r\n";
   },

   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request(
         method: 'GET',
         URI: '/chunked-large'
      );
   },

   test: function (Response $Response) use ($payload): Generator {
      yield assert(
         assertion: $Response->code === 200,
         description: "large chunked response completes: code {$Response->code} ({$Response->status})"
      );
      yield assert(
         assertion: strlen($Response->Body->raw) === 140000,
         description: 'all 140000 body bytes arrive (got ' . strlen($Response->Body->raw) . ')'
      );
      yield assert(
         assertion: $Response->Body->raw === $payload,
         description: 'no interior byte window is a replay of earlier bytes'
      );
   }
);
