<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Decoders\Decoder_Chunked;


// ? HCLI-1 contract — Decoder_Chunked OWNS every byte it was fed: decode()
//   absorbs the buffer into its internal leftover and a null return means
//   "absorbed, need more", never "kept your buffer". The caller must
//   therefore feed each byte exactly once (clearing its pending buffer);
//   these controls pin the incremental-feed contract the fix relies on.
return new Test(
   description: 'It should decode incrementally fed chunk streams exactly once',
   test: function () {
      // @ Incremental feed across 4 calls — split inside the chunk-size
      //   line, inside chunk data and at the terminator
      $Decoder = new Decoder_Chunked;
      $Decoder->init();
      $Decoder->feed("6\r\nALP");

      $receipt = $Decoder->decode('', 0, 'GET');
      yield assert(
         assertion: $receipt === null,
         description: 'a partial chunk absorbs and waits'
      );

      $receipt = $Decoder->decode("HA1\r\n6\r\nBRA", 11, 'GET');
      yield assert(
         assertion: $receipt === null,
         description: 'a split inside the next chunk-size line absorbs and waits'
      );

      $receipt = $Decoder->decode("VO2\r\n", 5, 'GET');
      yield assert(
         assertion: $receipt === null,
         description: 'a completed middle chunk still waits for the terminator'
      );

      $receipt = $Decoder->decode("0\r\n\r\n", 5, 'GET');
      yield assert(
         assertion: $receipt !== null
            && ($receipt['complete'] ?? false) === true
            && $receipt['body'] === 'ALPHA1BRAVO2'
            && $receipt['bodyLength'] === 12,
         description: "each byte decodes exactly once (got '{$receipt['body']}')"
      );

      // @ Pipelined bytes after the terminator come back in leftover
      $Decoder = new Decoder_Chunked;
      $Decoder->init();
      $receipt = $Decoder->decode("5\r\nHello\r\n0\r\n\r\nHTTP/1.1 404", 27, 'GET');

      yield assert(
         assertion: $receipt !== null
            && $receipt['body'] === 'Hello'
            && $receipt['leftover'] === 'HTTP/1.1 404',
         description: 'bytes after the terminator return in leftover'
      );

      // @ Trailer section split across reads
      $Decoder = new Decoder_Chunked;
      $Decoder->init();
      $Decoder->feed("3\r\nabc\r\n0\r\nX-Trailer: v");

      $receipt = $Decoder->decode('', 0, 'GET');
      yield assert(
         assertion: $receipt === null,
         description: 'an incomplete trailer section waits'
      );

      $receipt = $Decoder->decode("\r\n\r\n", 4, 'GET');
      yield assert(
         assertion: $receipt !== null && $receipt['body'] === 'abc',
         description: 'the trailer terminator completes the stream'
      );

      // @ feed() is an assignment, not an append — the ownership handoff
      //   happens exactly once, before the first decode
      $Decoder = new Decoder_Chunked;
      $Decoder->init();
      $Decoder->feed("discarded");
      $Decoder->feed("3\r\nxyz\r\n0\r\n\r\n");
      $receipt = $Decoder->decode('', 0, 'GET');

      yield assert(
         assertion: $receipt !== null && $receipt['body'] === 'xyz',
         description: 'a second feed() replaces the first payload'
      );
   }
);
