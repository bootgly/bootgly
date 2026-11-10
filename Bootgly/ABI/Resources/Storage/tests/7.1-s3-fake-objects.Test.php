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
use function array_filter;
use function array_values;
use function assert;
use function count;
use function fake_skip;
use function grab;
use function sort;
use function source;
use function wipe;

use Bootgly\ABI\Resources\Storage\Drivers\S3;
use Bootgly\ABI\Resources\Storage\tests\fixtures\S3 as Fake;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


require_once __DIR__ . '/disk.php';
require_once __DIR__ . '/fixtures/S3.php';


/**
 * The object operations of the S3 driver against the local fake, which recomputes every
 * signature with its own SigV4 verifier: what `write()`, `read()`, `check()`, `measure()`,
 * `inspect()`, `list()`, `copy()`, `move()`, `delete()` and `clear()` do — and what they
 * report on failure — must not move when the driver's signing or transport is touched.
 */
return new Test(
   description: 'Storage(S3) fake: object operations, failures and the root prefix jail',
   skip: fake_skip(),
   test: function () {
      $base = Temporaries::reserve('storage-s3-objects');
      // ! Two keys per ListObjectsV2 page, so every listing below pages
      $Fake = Fake::start($base, page: 2);

      try {
         $S3 = new S3('media/library', $Fake->configure());

         // # write() — a single PUT carrying the Content-Type and the metadata
         yield assert(
            assertion: $S3->write('docs/a b/c+d&e=f?.txt', source('hello world'), [
               'type' => 'text/plain',
               'meta' => ['Owner' => 'rodrigo', 'pages' => 2],
            ]) === true && $S3->error === '',
            description: 'write() uploads a small object'
         );
         $Stored = $Fake->describe('media/library/docs/a b/c+d&e=f?.txt');
         yield assert(
            assertion: $Fake->fetch('media/library/docs/a b/c+d&e=f?.txt') === 'hello world'
               && ($Stored['type'] ?? '') === 'text/plain'
               && ($Stored['meta'] ?? []) === ['owner' => 'rodrigo', 'pages' => '2'],
            description: 'the object lands under the root prefix, key encoded once, with its type and metadata'
         );
         $Log = $Fake->log();
         yield assert(
            assertion: count($Log) === 1
               && $Log[0]['operation'] === 'PutObject'
               && $Log[0]['auth'] === 'header'
               && $Log[0]['status'] === 200
               && $Log[0]['target'] === '/test-bucket/media/library/docs/a%20b/c%2Bd%26e%3Df%3F.txt',
            description: 'one header-auth PUT, accepted by the independent signature check'
         );
         yield assert(
            assertion: $S3->write('plain.bin', source('')) === true
               && ($Fake->describe('media/library/plain.bin')['type'] ?? '') === 'application/octet-stream',
            description: 'write() defaults the Content-Type and accepts an empty object'
         );
         yield assert(
            assertion: $S3->write('crlf.txt', source('x'), ['meta' => ['a' => "b\r\nX-Injected: 1"]]) === false
               && $S3->error === 'S3 write /test-bucket/media/library/crlf.txt: illegal newline in write options'
               && $Fake->fetch('media/library/crlf.txt') === null,
            description: 'write() refuses a newline in its options before any request'
         );
         yield assert(
            assertion: $S3->write('odd.txt', source('x'), ['meta' => ['bad name' => 'v']]) === false
               && $S3->error === 'S3 write /test-bucket/media/library/odd.txt: illegal character in write options'
               && $S3->write('odd.txt', source('x'), ['type' => "text/plain\x0b"]) === false
               && $S3->error === 'S3 write /test-bucket/media/library/odd.txt: illegal character in write options'
               && $Fake->fetch('media/library/odd.txt') === null
               && count($Fake->log()) === 2,
            description: 'write() refuses a metadata name that is not a header token and a control character, with that reason and no request'
         );
         $Fake->inject('PutObject', 500);
         yield assert(
            assertion: $S3->write('failing.txt', source('x')) === false
               && $S3->error === 'S3 PUT /test-bucket/media/library/failing.txt: HTTP 500',
            description: 'write() reports the HTTP status of a failed PUT'
         );

         // # read()
         yield assert(
            assertion: grab($S3, 'docs/a b/c+d&e=f?.txt') === 'hello world' && $S3->error === '',
            description: 'read() streams the object back'
         );
         yield assert(
            assertion: grab($S3, 'missing.txt') === false
               && $S3->error === 'S3 GET /test-bucket/media/library/missing.txt: HTTP 404',
            description: 'read() of a missing key is false with the HTTP status'
         );
         $Fake->inject('GetObject', 200, raw: "HTTP/1.1 200 OK\r\nContent-Length: 11\r\n\r\nhello");
         yield assert(
            assertion: grab($S3, 'docs/a b/c+d&e=f?.txt') === false
               && $S3->error === 'S3 GET: incomplete response body',
            description: 'read() of a body cut before its Content-Length is false, never a short success'
         );
         $Fake->inject('GetObject', 200, raw: "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nhello\r\n6\r\n world\r\n0\r\n\r\n");
         yield assert(
            assertion: grab($S3, 'docs/a b/c+d&e=f?.txt') === 'hello world',
            description: 'read() decodes a chunked body'
         );
         $Fake->inject('GetObject', 0);
         yield assert(
            assertion: grab($S3, 'docs/a b/c+d&e=f?.txt') === false
               && $S3->error === 'S3 GET /test-bucket/media/library/docs/a%20b/c%2Bd%26e%3Df%3F.txt: read failed',
            description: 'read() of a dropped connection is false with the generic reason'
         );

         // # check() / measure() / inspect()
         yield assert(
            assertion: $S3->check('docs/a b/c+d&e=f?.txt') === true && $S3->check('missing.txt') === false,
            description: 'check() sees an object and not a missing one'
         );
         yield assert(
            assertion: $S3->measure('docs/a b/c+d&e=f?.txt') === 11 && $S3->measure('missing.txt') === false,
            description: 'measure() reports the size, false when missing'
         );
         $Inspected = $S3->inspect('docs/a b/c+d&e=f?.txt');
         yield assert(
            assertion: $Inspected !== false && $Inspected['size'] === 11 && $Inspected['modified'] > 0
               && $S3->inspect('missing.txt') === false,
            description: 'inspect() reports size and modification time, false when missing'
         );

         // # The root prefix jail: traversal is clamped inside the prefix
         yield assert(
            assertion: $S3->write('../../escape.txt', source('jailed')) === true
               && $Fake->fetch('media/library/escape.txt') === 'jailed'
               && $Fake->fetch('escape.txt') === null,
            description: 'a path climbing above the root lands inside the root prefix'
         );

         // # list() — paged, relative to the root
         $S3->write('docs/b.txt', source('b'));
         $S3->write('docs/deep/c.txt', source('c'));
         $Fake->seed('media/other.txt', 'outside the prefix');
         $recursive = $S3->list('', true);
         sort($recursive);
         yield assert(
            assertion: $recursive === ['docs/a b/c+d&e=f?.txt', 'docs/b.txt', 'docs/deep/c.txt', 'escape.txt', 'plain.bin']
               && $S3->error === '',
            description: 'list() recursive pages through every key under the root, relative to it'
         );
         yield assert(
            assertion: $S3->list('docs') === ['docs/b.txt'] && $S3->list() === ['escape.txt', 'plain.bin'],
            description: 'list() non-recursive returns only the direct keys'
         );
         $Fake->inject('ListObjectsV2', 503);
         yield assert(
            assertion: $S3->list('docs', true) === [] && $S3->error === 'S3 list media/library/docs/: HTTP 503',
            description: 'list() reports a failed page'
         );

         // # copy() / move() / delete()
         yield assert(
            assertion: $S3->copy('docs/b.txt', 'copies/b 2.txt') === true
               && $Fake->fetch('media/library/copies/b 2.txt') === 'b'
               && $Fake->fetch('media/library/docs/b.txt') === 'b',
            description: 'copy() duplicates the object'
         );
         yield assert(
            assertion: $S3->copy('missing.txt', 'copies/none.txt') === false,
            description: 'copy() of a missing source is false'
         );
         yield assert(
            assertion: $S3->move('docs/b.txt', 'moved/b.txt') === true
               && $Fake->fetch('media/library/moved/b.txt') === 'b'
               && $Fake->fetch('media/library/docs/b.txt') === null,
            description: 'move() copies then deletes the source'
         );
         yield assert(
            assertion: $S3->delete('moved/b.txt') === true && $S3->delete('moved/b.txt') === true
               && $Fake->fetch('media/library/moved/b.txt') === null,
            description: 'delete() removes the object and is idempotent'
         );
         $Fake->inject('DeleteObject', 500);
         yield assert(
            assertion: $S3->delete('plain.bin') === false && $S3->error === 'S3 DELETE plain.bin: HTTP 500',
            description: 'delete() reports the HTTP status of a failed DELETE'
         );

         // # clear() — only the root prefix
         yield assert(
            assertion: $S3->clear() === true
               && $S3->list('', true) === []
               && $Fake->fetch('media/other.txt') === 'outside the prefix',
            description: 'clear() removes every key under the root and nothing outside it'
         );

         // # Every request above was header auth and passed the signature check
         $Refused = array_values(array_filter($Fake->log(), static fn (array $Request): bool => $Request['status'] === 403));
         yield assert(
            assertion: $Refused === [] && array_filter(array_column($Fake->log(), 'auth'), static fn (string $auth): bool => $auth !== 'header') === [],
            description: 'no request was refused by the signature check'
         );
      }
      finally {
         $Fake->stop();
         wipe($base);
      }
   }
);
