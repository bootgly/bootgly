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


use function array_column;
use function array_values;
use function assert;
use function count;
use function fake_skip;
use function fopen;
use function preg_match;
use function rewind;
use function source;
use function str_contains;
use function str_repeat;
use function stream_get_contents;
use function strlen;
use function wipe;

use Bootgly\ABI\Resources\Storage\Drivers\S3;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Failure\Kinds;
use Bootgly\ABI\Resources\Storage\tests\fixtures\S3 as Fake;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


require_once __DIR__ . '/disk.php';
require_once __DIR__ . '/fixtures/S3.php';


/**
 * `$S3->Failure` — the status, the store's code and the kind of the failed request — on every
 * public call of the disk, against the local fake: what a caller branches on (a missing key, a
 * refusal to retry, a dropped connection), what never reaches a sink, and which request a
 * call made of several requests names.
 */
return new Test(
   description: 'Storage(S3) fake: every call leaves its failure (status, code, kind) on $Failure; an error body never reaches the sink',
   skip: fake_skip(),
   test: function () {
      $base = Temporaries::reserve('storage-s3-failures');
      $Fake = Fake::start($base);

      try {
         $S3 = new S3('', $Fake->configure());
         $Fake->seed('a.txt', 'alpha');

         // # read(): a missing key — the 404 and its code, an empty sink
         $Sink = fopen('php://memory', 'r+');
         $read = $S3->read('missing.bin', $Sink);
         rewind($Sink);
         yield assert(
            assertion: $read === false
               && stream_get_contents($Sink) === ''
               && $S3->error === 'S3 GET /test-bucket/missing.bin: HTTP 404'
               && $S3->Failure?->status === 404
               && $S3->Failure->code === 'NoSuchKey'
               && $S3->Failure->kind === Kinds::Refusal
               && $S3->Failure->message === $S3->error,
            description: 'a missing key is a Refusal 404 NoSuchKey, and the error document never reaches the sink'
         );

         // # read(): a store error for the whole object and for a window
         $Fake->inject('GetObject', 503, 'SlowDown');
         $Sink = fopen('php://memory', 'r+');
         $read = $S3->read('a.txt', $Sink);
         rewind($Sink);
         $whole = $read === false && stream_get_contents($Sink) === '' && $S3->Failure?->code === 'SlowDown';
         $Fake->inject('GetObject', 500);
         $Sink = fopen('php://memory', 'r+');
         $read = $S3->read('a.txt', $Sink, 1, 2);
         rewind($Sink);
         yield assert(
            assertion: $whole === true
               && $read === false
               && stream_get_contents($Sink) === ''
               && $S3->Failure?->status === 500
               && $S3->Failure->code === 'InternalError',
            description: 'a 5xx answer to a read — whole or ranged — keeps its code and leaves the sink empty'
         );

         // # read(): a window past the end — the 416 and its code
         $Sink = fopen('php://memory', 'r+');
         yield assert(
            assertion: $S3->read('a.txt', $Sink, 10) === false
               && $S3->error === 'S3 GET /test-bucket/a.txt: range not satisfiable (HTTP 416)'
               && $S3->Failure?->status === 416
               && $S3->Failure->code === 'InvalidRange'
               && $S3->Failure->kind === Kinds::Refusal,
            description: 'a window past the end is a Refusal 416 InvalidRange'
         );

         // # read(): a 200 to a ranged read — the whole object is not the window
         $Fake->inject('GetObject', 200, raw: "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nalpha");
         $Sink = fopen('php://memory', 'r+');
         $read = $S3->read('a.txt', $Sink, 1, 2);
         rewind($Sink);
         yield assert(
            assertion: $read === false
               && stream_get_contents($Sink) === ''
               && $S3->Failure?->status === 200
               && $S3->Failure->kind === Kinds::Protocol,
            description: 'a 200 to a ranged read is a Protocol failure and writes nothing'
         );

         // # The code is the store's, only when it is a code
         $codes = [];
         foreach (["No\r\nSuchKey", 'Access Denied', str_repeat('A', 65), '9Code', 'Bad<Code>'] as $raw) {
            $Fake->inject('DeleteObject', 500, $raw);
            $codes[] = $S3->delete('a.txt') === false && $S3->Failure?->status === 500 ? $S3->Failure->code : null;
         }
         $Fake->inject('DeleteObject', 500, 'Slow.Down2');
         $S3->delete('a.txt');
         yield assert(
            assertion: $codes === ['', '', '', '', ''] && $S3->Failure?->code === 'Slow.Down2',
            description: 'a <Code> with a line break, a blank, markup, a leading digit or over 64 characters is dropped, the status kept'
         );

         // # An error document past 64 KiB: the status stays, the code goes, nothing is written
         $XML = '<?xml version="1.0" encoding="UTF-8"?><Error><Code>InternalError</Code></Error>';
         $pad = 70 * 1024;
         $Fake->inject('GetObject', 0, raw: "HTTP/1.1 500 Internal Server Error\r\nContent-Type: application/xml\r\nContent-Length: " . (strlen($XML) + $pad) . "\r\n\r\n{$XML}", pad: $pad);
         $Sink = fopen('php://memory', 'r+');
         $read = $S3->read('a.txt', $Sink);
         rewind($Sink);
         yield assert(
            assertion: $read === false
               && stream_get_contents($Sink) === ''
               && $S3->Failure?->status === 500
               && $S3->Failure->code === ''
               && $S3->Failure->kind === Kinds::Refusal,
            description: 'an error answer over 64 KiB keeps its status but not its code, and never reaches the sink'
         );

         // # copy() / move(): a copy the store reports failed inside a 200
         $Fake->inject('CopyObject', 200, 'InternalError');
         yield assert(
            assertion: $S3->move('a.txt', 'b.txt') === false
               && $Fake->fetch('a.txt') === 'alpha'
               && $Fake->fetch('b.txt') === null
               && $S3->error === 'S3 COPY /test-bucket/b.txt: HTTP 200'
               && $S3->Failure?->status === 200
               && $S3->Failure->code === 'InternalError'
               && $S3->Failure->kind === Kinds::Refusal,
            description: 'a copy failed inside a 200 fails the move and keeps the source (no data loss)'
         );
         yield assert(
            assertion: $S3->move('nothing.txt', 'c.txt') === false
               && $S3->Failure?->status === 404
               && $S3->Failure->code === 'NoSuchKey'
               && $S3->move('a.txt', 'b.txt') === true
               && $S3->Failure === null
               && $S3->error === ''
               && $Fake->fetch('a.txt') === null
               && $Fake->fetch('b.txt') === 'alpha',
            description: 'a move of a missing source names the copy that failed; a move that works clears it'
         );

         // # check(): a missing key is an answer, a refusal is a failure
         $Fake->inject('HeadObject', 500);
         $S3->check('b.txt');
         $missing = $S3->check('missing.bin');
         yield assert(
            assertion: $missing === false && $S3->Failure === null && $S3->error === '',
            description: 'check() of a missing key is false with no failure (a 404 is an answer)'
         );
         $Fake->inject('HeadObject', 403, 'AccessDenied');
         yield assert(
            assertion: $S3->check('b.txt') === false
               && $S3->error === 'S3 HEAD /test-bucket/b.txt: HTTP 403'
               && $S3->Failure?->status === 403
               && $S3->Failure->code === ''
               && $S3->Failure->kind === Kinds::Refusal
               && $S3->check('b.txt') === true
               && $S3->Failure === null,
            description: 'check() refused (403) is false with a failure — a HEAD answer has no body, so no code'
         );

         // # measure() / inspect(): the reason of a missing key, and a clean start
         yield assert(
            assertion: $S3->measure('missing.bin') === false
               && $S3->error === 'S3 HEAD /test-bucket/missing.bin: HTTP 404'
               && $S3->Failure?->status === 404
               && $S3->measure('b.txt') === 5
               && $S3->error === ''
               && $S3->Failure === null
               && $S3->inspect('missing.bin') === false
               && $S3->error === 'S3 HEAD /test-bucket/missing.bin: HTTP 404'
               && $S3->Failure?->status === 404
               && $S3->inspect('b.txt') !== false
               && $S3->error === ''
               && $S3->Failure === null,
            description: 'measure() and inspect() of a missing key say why; a later call that works starts clean'
         );
         $Fake->inject('HeadObject', 200, raw: "HTTP/1.1 200 OK\r\nETag: \"x\"\r\n\r\n");
         yield assert(
            assertion: $S3->measure('b.txt') === false
               && $S3->error === 'S3 HEAD /test-bucket/b.txt: no Content-Length'
               && $S3->Failure?->kind === Kinds::Protocol
               && $S3->Failure->status === 200,
            description: 'a HEAD answer without Content-Length is a Protocol failure of measure()'
         );

         // # A refusal of the driver (nothing sent) vs a dropped connection
         $Fake->inject('PutObject', 0);
         yield assert(
            assertion: $S3->write('c.txt', source('gamma')) === false
               && $S3->Failure?->status === 0
               && $S3->Failure->kind === Kinds::Transport
               && $S3->write('c.txt', source('gamma'), ['type' => "text/plain\r\nX-Evil: 1"]) === false
               && $S3->Failure?->status === 0
               && $S3->Failure->kind === Kinds::Refusal
               && $S3->Failure->message === $S3->error,
            description: 'a dropped connection is Transport; an input the driver refuses to send is a Refusal'
         );
         $Closed = new S3('', $Fake->configure(['endpoint' => 'http://127.0.0.1:1']));
         $Unsignable = new S3('', $Fake->configure(['bucket' => 'my bucket']));
         yield assert(
            assertion: $Closed->check('a.txt') === false
               && $Closed->Failure?->kind === Kinds::Transport
               && $Closed->Failure->status === 0
               && $Unsignable->check('a.txt') === false
               && $Unsignable->Failure?->kind === Kinds::Refusal
               && $Unsignable->Failure->status === 0
               && str_contains($Unsignable->Failure->message, 'the request cannot be signed') === true,
            description: 'an endpoint that does not answer is Transport; a bucket that cannot be signed is a Refusal'
         );

         // # clear(): the first request that failed
         $Fake->seed('dir/1.txt', '1');
         $Fake->seed('dir/2.txt', '2');
         $Fake->seed('dir/3.txt', '3');
         $Fake->inject('DeleteObject', 403, 'AccessDenied');
         $Fake->inject('DeleteObject', 500, 'InternalError');
         yield assert(
            assertion: $S3->clear('dir') === false
               && $S3->error === 'S3 clear dir: one or more deletes failed'
               && $S3->Failure?->status === 403
               && $S3->Failure->code === 'AccessDenied'
               && $S3->Failure->message === 'S3 DELETE dir/1.txt: HTTP 403'
               && count($S3->list('dir', true)) === 2,
            description: 'clear() names the first delete refused — not a later one that failed too, nor the ones that succeeded'
         );
         $Fake->inject('ListObjectsV2', 503, 'SlowDown');
         yield assert(
            assertion: $S3->clear('dir') === false
               && $S3->Failure?->status === 503
               && $S3->Failure->code === 'SlowDown'
               && $S3->clear('dir') === true
               && $S3->Failure === null,
            description: 'clear() with a listing refused names the listing; a clear that works clears it'
         );

         // # copy() starts clean on its own
         $Fake->inject('HeadObject', 403, 'AccessDenied');
         $S3->measure('b.txt');
         $before = $S3->error;
         yield assert(
            assertion: $before === 'S3 HEAD /test-bucket/b.txt: HTTP 403'
               && $S3->copy('b.txt', 'copied.txt') === true
               && $S3->error === ''
               && $S3->Failure === null
               && $Fake->fetch('copied.txt') === 'alpha',
            description: 'copy() clears the $error and the $Failure an earlier call left'
         );

         // # A delete on a bucket that is gone is no delete
         $Fake->inject('DeleteObject', 404, 'NoSuchBucket');
         $gone = $S3->delete('copied.txt');
         $Fake->inject('DeleteObject', 404, 'NoSuchKey');
         yield assert(
            assertion: $gone === false
               && $S3->Failure?->code === 'NoSuchBucket'
               && $S3->delete('copied.txt') === true
               && $S3->Failure === null,
            description: 'a 404 NoSuchBucket fails delete(); a 404 NoSuchKey is a key already gone'
         );

         // # Answers too large to be S3 answers
         $Fake->inject('GetObject', 0, raw: "HTTP/1.1 200 OK\r\nX-Pad: ", pad: 70 * 1024);
         $Sink = fopen('php://memory', 'r+');
         $read = $S3->read('b.txt', $Sink);
         rewind($Sink);
         yield assert(
            assertion: $read === false
               && stream_get_contents($Sink) === ''
               && $S3->Failure?->kind === Kinds::Protocol
               && $S3->error === 'S3 GET: the answer head is too large to be an S3 answer',
            description: 'an answer head that never ends fails the read past 64 KiB (Protocol), nothing written'
         );

         // # A large write: the part that failed, not the abort after it
         $part = 16 * 1024 * 1024;
         $bytes = str_repeat('x', $part + 5);
         $Fake->inject('UploadPart', 500, 'InternalError');
         $Fake->inject('AbortMultipartUpload', 503, 'SlowDown');
         $written = $S3->write('big/part.bin', source($bytes));
         $Operations = array_values(array_column($Fake->log(), 'operation'));
         yield assert(
            assertion: $written === false
               && preg_match('/^S3 upload part [12]: HTTP 500$/', $S3->error) === 1
               && $S3->Failure?->status === 500
               && $S3->Failure->code === 'InternalError'
               && $S3->Failure->message === $S3->error
               && ($Operations[count($Operations) - 1] ?? '') === 'AbortMultipartUpload',
            description: 'a large write names the part that failed, even when the abort after it fails too'
         );
         // ! Twice: the other part of the write above, left in flight by its abort, may still reach
         //   the fake and take one — this write's own parts take what is left
         $Fake->inject('UploadPart', 0, raw: "HTTP/1.1 200 OK\r\nETag: \"x\"\r\nContent-Length: 0\r\n\r\n", times: 2, pad: 33 * 1024 * 1024);
         $written = $S3->write('big/answer.bin', source($bytes));
         yield assert(
            assertion: $written === false
               && preg_match('/^S3 upload part [12]: the answer is too large to be an S3 answer$/', $S3->error) === 1
               && $S3->Failure?->kind === Kinds::Protocol,
            description: 'the answer to one part of a large write is capped: past 32 MiB it fails the write (Protocol)'
         );
      }
      finally {
         $Fake->stop();
         wipe($base);
      }
   }
);
