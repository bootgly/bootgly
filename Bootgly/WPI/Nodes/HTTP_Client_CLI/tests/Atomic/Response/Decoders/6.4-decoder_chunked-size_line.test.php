<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Decoders\Decoder_Chunked;


// ? HCLI-19 contract — RFC 9112 §7.1 says chunk-size = 1*HEXDIG, but
//   `hexdec()` silently ignores every invalid character and returns a FLOAT
//   above PHP_INT_MAX. Casting its result straight to int produced two wrong
//   outcomes: a bogus 0 (read as the TERMINAL chunk, completing the response
//   early with a truncated body) and a bogus negative (which made the slicing
//   arithmetic loop forever). The size line is now validated before it is
//   converted, mirroring the server decoder's audited parser.
return new Specification(
   description: 'It should reject chunk-size lines that are not well-formed hexadecimal',
   test: function () {
      // @ A 16-digit size line is a float for hexdec() and collapses to int 0 —
      //   it must NEVER be taken as the terminal chunk
      $Decoder = new Decoder_Chunked;
      $Decoder->init();
      $receipt = $Decoder->decode("5\r\nhello\r\nffffffffffffffff\r\n\r\n\r\n", 31, 'GET');

      yield assert(
         assertion: $receipt !== null
            && ($receipt['failed'] ?? false) === true
            && $receipt['status'] === 'Response Too Large'
            && isSet($receipt['complete']) === false,
         description: 'an over-PHP_INT_MAX size line fails instead of completing the response early'
      );

      // @ A size line that casts to a NEGATIVE int used to spin the decode
      //   loop forever — this call must simply return
      $Decoder = new Decoder_Chunked;
      $Decoder->init();
      $receipt = $Decoder->decode("8000000000000000\r\nxx\r\n", 22, 'GET');

      yield assert(
         assertion: $receipt !== null && ($receipt['failed'] ?? false) === true,
         description: 'a size line casting to a negative int returns instead of looping forever'
      );

      // @ Every shape `hexdec()` would silently accept is rejected
      $malformed = [
         'zzz'       => 'non-hex garbage',
         '-1'        => 'a signed size',
         '0x10'      => 'a 0x-prefixed size',
         '5 garbage' => 'a size with trailing garbage',
         ''          => 'an empty size line',
      ];
      foreach ($malformed as $line => $shape) {
         $Decoder = new Decoder_Chunked;
         $Decoder->init();
         $receipt = $Decoder->decode("{$line}\r\nxx\r\n0\r\n\r\n", 20, 'GET');

         yield assert(
            assertion: $receipt !== null
               && ($receipt['failed'] ?? false) === true
               && $receipt['status'] === 'Invalid Chunked Encoding',
            description: "{$shape} ('{$line}') is rejected as invalid chunked encoding"
         );
      }

      // @ Controls — conformant framing keeps decoding exactly as before
      $Decoder = new Decoder_Chunked;
      $Decoder->init();
      $receipt = $Decoder->decode("00000005\r\nhello\r\n0\r\n\r\n", 22, 'GET');

      yield assert(
         assertion: $receipt !== null && ($receipt['complete'] ?? false) === true && $receipt['body'] === 'hello',
         description: 'leading zeros are legal (RFC 9112 §7.1) and still decode'
      );

      // @ An exponent-SHAPED size is still valid hexadecimal — `0e0` is 224,
      //   not a malformed token; the validator must not over-reject
      $Decoder = new Decoder_Chunked;
      $Decoder->init();
      $receipt = $Decoder->decode("0e0\r\n", 5, 'GET');

      yield assert(
         assertion: $receipt === null,
         description: 'an exponent-shaped but valid hex size (0e0 = 224) is accepted and waits for data'
      );

      // @ BWS before the chunk-extension delimiter stays on the size side and
      //   must not make the size unparseable
      $Decoder = new Decoder_Chunked;
      $Decoder->init();
      $receipt = $Decoder->decode("5 ;name=value\r\nhello\r\n0\r\n\r\n", 27, 'GET');

      yield assert(
         assertion: $receipt !== null && ($receipt['complete'] ?? false) === true && $receipt['body'] === 'hello',
         description: 'trailing BWS before a chunk-extension is tolerated'
      );

      // @ The largest accepted magnitude still parses as a size (its data
      //   simply never arrives), one digit more is refused
      $Decoder = new Decoder_Chunked;
      $Decoder->init();
      $receipt = $Decoder->decode("fffffffffffffff\r\n", 17, 'GET');

      yield assert(
         assertion: $receipt === null,
         description: '15 significant digits are accepted as a size (unbounded decoder waits for data)'
      );

      $Decoder = new Decoder_Chunked;
      $Decoder->init();
      $receipt = $Decoder->decode("1000000000000000\r\n", 18, 'GET');

      yield assert(
         assertion: $receipt !== null
            && ($receipt['failed'] ?? false) === true
            && $receipt['status'] === 'Response Too Large',
         description: '16 significant digits are refused as too large'
      );
   }
);
