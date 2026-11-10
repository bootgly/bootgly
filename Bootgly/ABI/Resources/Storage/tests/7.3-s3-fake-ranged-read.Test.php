<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Storage;


use const PHP_INT_MAX;
use function assert;
use function count;
use function fake_skip;
use function fopen;
use function rewind;
use function str_contains;
use function stream_get_contents;
use function wipe;
use ReflectionMethod;

use Bootgly\ABI\Resources\Storage;
use Bootgly\ABI\Resources\Storage\Drivers\S3;
use Bootgly\ABI\Resources\Storage\tests\fixtures\S3 as Fake;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


require_once __DIR__ . '/disk.php';
require_once __DIR__ . '/fixtures/S3.php';


/**
 * The ranged `S3::read()` — `offset` and `length` as trailing optional parameters — against
 * the local fake: the window that was asked for reaches the sink, or nothing does. An
 * endpoint that ignores the Range, answers another window or sends more bytes than it
 * announced is a failure, never a silent success with the wrong bytes.
 */
return new Test(
   description: 'Storage(S3) fake: read() of a byte range streams exactly the window, or fails',
   skip: fake_skip(),
   test: function () {
      $base = Temporaries::reserve('storage-s3-ranged-read');
      $Fake = Fake::start($base);

      // ! One window read into memory → [result, bytes the sink received]
      $window = static function (S3 $S3, string $path, int $offset, null|int $length): array {
         $Sink = fopen('php://temp', 'r+');
         $read = $S3->read($path, $Sink, $offset, $length);
         rewind($Sink);

         return [$read, (string) stream_get_contents($Sink)];
      };

      try {
         $S3 = new S3('media', $Fake->configure());
         $Fake->seed('media/letters.txt', 'abcdefghijklmnopqrstuvwxyz', 'text/plain');
         $key = '/test-bucket/media/letters.txt';

         // # The window
         yield assert(
            assertion: $window($S3, 'letters.txt', 0, 5) === [true, 'abcde'] && $S3->error === '',
            description: 'the first bytes: offset 0, length 5'
         );
         $Log = $Fake->log();
         $Last = $Log[count($Log) - 1];
         yield assert(
            assertion: $Last['operation'] === 'GetObject'
               && $Last['status'] === 206
               && $Last['auth'] === 'header'
               && ($Last['headers']['range'] ?? '') === 'bytes=0-4'
               && str_contains($Last['headers']['authorization'] ?? '', 'SignedHeaders=host;range;x-amz-content-sha256;x-amz-date,') === true,
            description: 'one GET carrying `Range: bytes=0-4`, and the Range is signed'
         );
         yield assert(
            assertion: $window($S3, 'letters.txt', 10, 5) === [true, 'klmno']
               && $window($S3, 'letters.txt', 25, 1) === [true, 'z'],
            description: 'a window in the middle, and the last byte'
         );
         yield assert(
            assertion: $window($S3, 'letters.txt', 20, null) === [true, 'uvwxyz']
               && ($Fake->log()[count($Fake->log()) - 1]['headers']['range'] ?? '') === 'bytes=20-',
            description: 'an offset with no length reads to the end of the object (`bytes=20-`)'
         );
         yield assert(
            assertion: $window($S3, 'letters.txt', 20, 100) === [true, 'uvwxyz']
               && $window($S3, 'letters.txt', 0, 26) === [true, 'abcdefghijklmnopqrstuvwxyz'],
            description: 'a window past the end is cut at the end; a window that is the whole object is still a window'
         );

         // # The whole object is what it always was: no Range header
         $before = count($Fake->log());
         yield assert(
            assertion: $window($S3, 'letters.txt', 0, null) === [true, 'abcdefghijklmnopqrstuvwxyz']
               && isset($Fake->log()[$before]['headers']['range']) === false
               && $Fake->log()[$before]['status'] === 200,
            description: 'offset 0 with no length sends no Range and is answered 200'
         );
         yield assert(
            assertion: (new ReflectionMethod(Driver::class, 'read'))->getNumberOfParameters() === 2
               && (new ReflectionMethod(Storage::class, 'read'))->getNumberOfParameters() === 2
               && (new ReflectionMethod(S3::class, 'read'))->getNumberOfRequiredParameters() === 2,
            description: 'the Driver contract and the Storage facade keep `read(path, sink)`: the range is S3 only'
         );

         // # Refused before any request
         $before = count($Fake->log());
         $invalid = "S3 GET {$key}: invalid range (offset >= 0, length >= 1)";
         yield assert(
            assertion: $window($S3, 'letters.txt', -1, 5) === [false, ''] && $S3->error === $invalid
               && $window($S3, 'letters.txt', 0, 0) === [false, ''] && $S3->error === $invalid
               && $window($S3, 'letters.txt', 3, -5) === [false, ''] && $S3->error === $invalid
               && $window($S3, 'letters.txt', 1, PHP_INT_MAX) === [false, ''] && $S3->error === $invalid
               && count($Fake->log()) === $before,
            description: 'a negative offset, a length below 1 and a window that overflows are refused with no request'
         );

         // # Failures leave the sink empty
         yield assert(
            assertion: $window($S3, 'letters.txt', 26, 5) === [false, '']
               && $S3->error === "S3 GET {$key}: range not satisfiable (HTTP 416)"
               && $window($S3, 'letters.txt', 500, null) === [false, '']
               && $S3->error === "S3 GET {$key}: range not satisfiable (HTTP 416)",
            description: 'an offset at or past the end is false with the 416, and the error body never reaches the sink'
         );
         yield assert(
            assertion: $window($S3, 'missing.txt', 5, 5) === [false, '']
               && $S3->error === 'S3 GET /test-bucket/media/missing.txt: HTTP 404',
            description: 'a missing key is false with the HTTP status, the sink untouched'
         );
         $Fake->inject('GetObject', 200, raw: "HTTP/1.1 200 OK\r\nContent-Length: 26\r\n\r\nabcdefghijklmnopqrstuvwxyz");
         yield assert(
            assertion: $window($S3, 'letters.txt', 10, 5) === [false, '']
               && $S3->error === "S3 GET {$key}: range ignored by the endpoint (HTTP 200)",
            description: 'an endpoint that ignores the Range and answers the whole object is a failure, not 26 bytes in the sink'
         );
         $Fake->inject('GetObject', 206, raw: "HTTP/1.1 206 Partial Content\r\nContent-Range: bytes 0-4/26\r\nContent-Length: 5\r\n\r\nabcde");
         yield assert(
            assertion: $window($S3, 'letters.txt', 10, 5) === [false, '']
               && $S3->error === 'S3 GET: the answer is not the requested range',
            description: 'a 206 for another window is refused'
         );
         $Fake->inject('GetObject', 206, raw: "HTTP/1.1 206 Partial Content\r\nContent-Range: bytes 10-20/26\r\nContent-Length: 11\r\n\r\nklmnopqrstu");
         yield assert(
            assertion: $window($S3, 'letters.txt', 10, 5) === [false, '']
               && $S3->error === 'S3 GET: the answer is not the requested range',
            description: 'a 206 for a longer window than asked is refused'
         );
         $Fake->inject('GetObject', 206, raw: "HTTP/1.1 206 Partial Content\r\nContent-Length: 5\r\n\r\nklmno");
         yield assert(
            assertion: $window($S3, 'letters.txt', 10, 5) === [false, '']
               && $S3->error === 'S3 GET: the answer is not the requested range',
            description: 'a 206 with no Content-Range is refused'
         );
         $Fake->inject('GetObject', 206, raw: "HTTP/1.1 206 Partial Content\r\nContent-Range: bytes 10-14/26\r\nContent-Length: 9\r\n\r\nklmnopqrs");
         yield assert(
            assertion: $window($S3, 'letters.txt', 10, 5) === [false, '']
               && $S3->error === 'S3 GET: the answer is not the requested range',
            description: 'a Content-Length that disagrees with the announced window is refused'
         );
         $Fake->inject('GetObject', 206, raw: "HTTP/1.1 206 Partial Content\r\nContent-Range: bytes 10-14/26\r\n\r\nklmnopqrstuvwxyz");
         yield assert(
            assertion: $window($S3, 'letters.txt', 10, 5) === [true, 'klmno'],
            description: 'a body longer than the announced window never reaches the sink past the window'
         );
         $Fake->inject('GetObject', 206, raw: "HTTP/1.1 206 Partial Content\r\nContent-Range: bytes 10-14/26\r\nContent-Length: 5\r\n\r\nklm");
         [$read] = $window($S3, 'letters.txt', 10, 5);
         yield assert(
            assertion: $read === false && $S3->error === 'S3 GET: incomplete response body',
            description: 'a window cut before its last byte is false, never a short success'
         );

         // # Chunked windows
         $Fake->inject('GetObject', 206, raw: "HTTP/1.1 206 Partial Content\r\nContent-Range: bytes 10-14/26\r\nTransfer-Encoding: chunked\r\n\r\n"
            . "3\r\nklm\r\n2\r\nno\r\n0\r\n\r\n");
         yield assert(
            assertion: $window($S3, 'letters.txt', 10, 5) === [true, 'klmno'],
            description: 'a chunked window is decoded'
         );
         $Fake->inject('GetObject', 206, raw: "HTTP/1.1 206 Partial Content\r\nContent-Range: bytes 10-14/26\r\nTransfer-Encoding: chunked\r\n\r\n"
            . "3\r\nklm\r\n6\r\nnopqrs\r\n0\r\n\r\n");
         [$read, $bytes] = $window($S3, 'letters.txt', 10, 5);
         yield assert(
            assertion: $read === false && $bytes === 'klm' && $S3->error === 'S3 GET: incomplete response body',
            description: 'a chunked body that outgrows the window is false, and nothing past the window reaches the sink'
         );
         $Fake->inject('GetObject', 206, raw: "HTTP/1.1 206 Partial Content\r\nContent-Range: bytes 10-14/26\r\nTransfer-Encoding: chunked\r\n\r\n"
            . "3\r\nklm\r\n0\r\n\r\n");
         [$read] = $window($S3, 'letters.txt', 10, 5);
         yield assert(
            assertion: $read === false && $S3->error === 'S3 GET: incomplete response body',
            description: 'a chunked body shorter than the window is false'
         );

         // # A store that does not know the object's size (`/*`)
         $Fake->inject('GetObject', 206, raw: "HTTP/1.1 206 Partial Content\r\nContent-Range: bytes 10-14/*\r\nContent-Length: 5\r\n\r\nklmno");
         yield assert(
            assertion: $window($S3, 'letters.txt', 10, 5) === [true, 'klmno'],
            description: 'a window announced with an unknown total size is accepted'
         );
         $Fake->inject('GetObject', 206, raw: "HTTP/1.1 206 Partial Content\r\nContent-Range: bytes 10-12/26\r\nContent-Length: 3\r\n\r\nklm");
         yield assert(
            assertion: $window($S3, 'letters.txt', 10, 5) === [false, '']
               && $S3->error === 'S3 GET: the answer is not the requested range',
            description: 'a window that ends early while the object goes on is refused'
         );

         // # The key goes through the same root prefix and encoding
         $Fake->seed('media/docs/a b/c+d.txt', '0123456789');
         yield assert(
            assertion: $window($S3, '../../docs/a b/c+d.txt', 2, 3) === [true, '234']
               && $Fake->log()[count($Fake->log()) - 1]['target'] === '/test-bucket/media/docs/a%20b/c%2Bd.txt',
            description: 'a ranged read stays inside the root prefix, the key encoded once'
         );
      }
      finally {
         $Fake->stop();
         wipe($base);
      }
   }
);
