<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Decoders\Decoder_Chunked;


// ? HCLI-11 contract — the decoded-body cap is a constructor input driven by
//   HTTP_Client_CLI::$maxResponseBytes (0 = unbounded, as documented) and a
//   breach returns a distinguishable OVERFLOW record, never a completion:
//   the old hard-coded 10 MiB cap answered with 'complete' => true and an
//   empty body, indistinguishable from a real terminator.
return new Specification(
   description: 'It should refuse chunk streams that exceed the configured cap',
   test: function () {
      // @ Default construction is unbounded — a declared 20 MiB chunk simply
      //   waits for data (the old default returned a fake completion here)
      $Decoder = new Decoder_Chunked;
      $Decoder->init();
      $receipt = $Decoder->decode("1400000\r\n", 9, 'GET');

      yield assert(
         assertion: $receipt === null,
         description: 'unbounded by default: a declared 20 MiB chunk waits for data'
      );

      // @ A configured cap fails fast on the DECLARED size — the chunk data
      //   never has to arrive for the breach to surface
      $Decoder = new Decoder_Chunked(65536);
      $Decoder->init();
      $receipt = $Decoder->decode("20000\r\n", 7, 'GET');

      yield assert(
         assertion: $receipt !== null
            && ($receipt['overflow'] ?? false) === true
            && isSet($receipt['complete']) === false,
         description: 'a declared 131072-byte chunk under a 65536 cap returns an overflow record, not a completion'
      );

      // @ Boundary: exactly at the cap decodes in full
      $data = str_repeat('x', 65536);
      $line = dechex(65536);
      $Decoder = new Decoder_Chunked(65536);
      $Decoder->init();
      $receipt = $Decoder->decode("{$line}\r\n{$data}\r\n0\r\n\r\n", strlen($data) + 14, 'GET');

      yield assert(
         assertion: $receipt !== null
            && ($receipt['complete'] ?? false) === true
            && $receipt['bodyLength'] === 65536,
         description: 'a chunk of exactly the cap size completes with the full body'
      );

      // @ Boundary: one byte over the cap overflows
      $Decoder = new Decoder_Chunked(65536);
      $Decoder->init();
      $receipt = $Decoder->decode("10001\r\n", 7, 'GET');

      yield assert(
         assertion: $receipt !== null && ($receipt['overflow'] ?? false) === true,
         description: 'a declared cap+1 chunk overflows'
      );

      // @ The cap guards the ACCUMULATED decoded body — a second chunk whose
      //   declared size would push the total past the cap overflows mid-stream
      $Decoder = new Decoder_Chunked(100);
      $Decoder->init();
      $sixty = str_repeat('y', 60);
      $receipt = $Decoder->decode("3c\r\n{$sixty}\r\n3c\r\n", 70, 'GET');

      yield assert(
         assertion: $receipt !== null && ($receipt['overflow'] ?? false) === true,
         description: 'a second 60-byte chunk under a 100-byte cap overflows the accumulated body'
      );

      // @ An overflow wipes decoder state — init() + a clean stream reuses
      //   the instance correctly
      $Decoder->init();
      $receipt = $Decoder->decode("3\r\nabc\r\n0\r\n\r\n", 13, 'GET');

      yield assert(
         assertion: $receipt !== null
            && ($receipt['complete'] ?? false) === true
            && $receipt['body'] === 'abc',
         description: 'after an overflow, init() restores a clean decoder'
      );
   }
);
