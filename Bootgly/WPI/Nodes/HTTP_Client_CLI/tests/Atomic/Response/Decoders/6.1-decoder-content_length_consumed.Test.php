<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Decoders\Decoder_;


// ? HCLI-10 regression — Decoder_ is stateless and re-parses from byte 0,
//   so a receipt may only charge bytes the caller can actually discard.
//   A partial Content-Length body must charge NOTHING (consumed = 0, like
//   the close-delimited siblings) so the caller keeps headers + partial
//   body in $pendingBuffer for re-parse on the next read.
return new Test(
   description: 'It should charge Content-Length only when the body is fully buffered',
   test: function () {
      $Decoder = new Decoder_;

      $header = "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 1000\r\n\r\n";
      $headerLength = strlen($header);

      // @ Header + partial body: nothing may be charged
      $partial = $header . str_repeat('A', 100);
      $receipt = $Decoder->decode($partial, strlen($partial), 'GET');

      yield assert(
         assertion: $receipt !== null && $receipt['bodyWaiting'] === true,
         description: 'partial body reports bodyWaiting'
      );
      yield assert(
         assertion: $receipt !== null && $receipt['consumed'] === 0,
         description: "partial body charges nothing (consumed = {$receipt['consumed']})"
      );

      // @ Header only, zero body bytes: same pin
      $receipt = $Decoder->decode($header, $headerLength, 'GET');

      yield assert(
         assertion: $receipt !== null && $receipt['bodyWaiting'] === true
            && $receipt['consumed'] === 0,
         description: 'header-only buffer with a declared body charges nothing'
      );

      // @ Invariant: a receipt never charges more than the buffer it was given
      foreach ([0, 1, 100, 999] as $bytes) {
         $buffer = $header . str_repeat('A', $bytes);
         $receipt = $Decoder->decode($buffer, strlen($buffer), 'GET');

         yield assert(
            assertion: $receipt !== null && $receipt['consumed'] <= strlen($buffer),
            description: "receipt never exceeds the buffer ({$bytes} body bytes: consumed {$receipt['consumed']})"
         );
      }

      // @ Control — full body charges header + Content-Length
      $full = $header . str_repeat('A', 1000);
      $receipt = $Decoder->decode($full, strlen($full), 'GET');

      yield assert(
         assertion: $receipt !== null
            && $receipt['consumed'] === $headerLength + 1000
            && $receipt['bodyWaiting'] === false
            && $receipt['bodyDownloaded'] === 1000,
         description: 'complete body charges header + Content-Length'
      );

      // @ Control — Content-Length: 0 charges the header section only
      $empty = "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n";
      $receipt = $Decoder->decode($empty, strlen($empty), 'GET');

      yield assert(
         assertion: $receipt !== null
            && $receipt['consumed'] === strlen($empty)
            && $receipt['bodyWaiting'] === false,
         description: 'Content-Length: 0 charges the header section only'
      );

      // @ Control — the caller's re-parse protocol: the same stateless
      //   decoder, later fed the full buffer, returns the complete body
      $receipt = $Decoder->decode($partial, strlen($partial), 'GET');
      $receipt = $Decoder->decode($full, strlen($full), 'GET');

      yield assert(
         assertion: $receipt !== null
            && $receipt['bodyRaw'] === str_repeat('A', 1000)
            && $receipt['bodyWaiting'] === false,
         description: 'a later full-buffer re-parse completes the response'
      );

      // @ Pipelined bytes after a complete body are never charged — the
      //   receipt slices exactly at the end of the response so the caller
      //   keeps the next response's head in $pendingBuffer.
      $pipelined = $full . "HTTP/1.1 404 Not Found\r\n";
      $receipt = $Decoder->decode($pipelined, strlen($pipelined), 'GET');

      yield assert(
         assertion: $receipt !== null
            && $receipt['consumed'] === $headerLength + 1000
            && $receipt['bodyWaiting'] === false,
         description: "pipelined leftover is not charged (consumed {$receipt['consumed']})"
      );

      // @ Same pin past the small-response cache ceiling (> 2048 bytes)
      $bigHeader = "HTTP/1.1 200 OK\r\nContent-Length: 3000\r\n\r\n";
      $big = $bigHeader . str_repeat('B', 3000) . "HTTP/1.1 204 No Content\r\n";
      $receipt = $Decoder->decode($big, strlen($big), 'GET');

      yield assert(
         assertion: $receipt !== null
            && $receipt['consumed'] === strlen($bigHeader) + 3000
            && $receipt['bodyDownloaded'] === 3000,
         description: 'a large complete body with trailing bytes charges exactly header + Content-Length'
      );
   }
);
