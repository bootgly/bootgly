<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Decoders\Decoder_Chunked;


// ? H-HCLI-1 — with maxResponseBytes = 0 nothing bounded a chunk-size line that never ends,
//   nor a trailer section that never ends: both grew the leftover until the process died.
//   Both are capped whatever the body limit allows.
return new Test(
   description: 'It should cap a chunk-size line and the trailer section even when the body is unbounded',
   test: function () {
      // ! An unbounded decoder, fed one read
      $feed = static function (string $data): null|array {
         $Chunked = new Decoder_Chunked(0);
         $Chunked->init();
         $Chunked->feed($data);

         return $Chunked->decode('', 0, 'GET');
      };
      $refused = static fn (null|array $parsed): bool => ($parsed['failed'] ?? false) === true
         && ($parsed['status'] ?? null) === 'Invalid Chunked Encoding';

      // # Chunk-size line
      $extension = ';' . str_repeat('e', 8200);
      yield assert(
         assertion: $feed('5' . substr($extension, 0, 8000)) === null
            && $refused($feed('5' . $extension))
            && $refused($feed("5{$extension}\r\nhello\r\n0\r\n\r\n")),
         description: 'a chunk-size line past 8192 bytes is refused, ended or not'
      );

      // # Trailer section
      $trailer = str_repeat('X-Trailer: ' . str_repeat('t', 1013) . "\r\n", 64);
      yield assert(
         assertion: $feed("5\r\nhello\r\n0\r\n" . substr($trailer, 0, 60000)) === null
            && $refused($feed("5\r\nhello\r\n0\r\n{$trailer}"))
            && $refused($feed("5\r\nhello\r\n0\r\n{$trailer}\r\n"))
            && ($feed("5\r\nhello\r\n0\r\nX-A: 1\r\n\r\n")['body'] ?? null) === 'hello',
         description: 'a trailer section past ' . Decoder_::MAX_HEADER_BYTES . ' bytes is refused; a small one completes'
      );
   }
);
