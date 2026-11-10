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
use function array_keys;
use function array_reverse;
use function array_slice;
use function array_values;
use function assert;
use function count;
use function fake_send;
use function fake_skip;
use function is_string;
use function md5;
use function preg_match;
use function preg_replace;
use function rawurlencode;
use function str_contains;
use function str_repeat;
use function str_replace;
use function str_starts_with;
use function trim;
use function wipe;

use Bootgly\ABI\Resources\Storage\Drivers\S3;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Uploads;
use Bootgly\ABI\Resources\Storage\tests\fixtures\S3 as Fake;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


require_once __DIR__ . '/disk.php';
require_once __DIR__ . '/fixtures/S3.php';


/**
 * The Multipart Upload handle (`$S3->Uploads`) end to end against the local fake: the
 * server creates the upload and signs each part, someone with no credentials `PUT`s the
 * parts straight to the store, the server lists what arrived and completes — or aborts.
 * The handle keeps no state: every step takes the path and the upload id.
 */
return new Test(
   description: 'Storage(S3) fake: Uploads — create, sign, list, complete and abort a direct upload',
   skip: fake_skip(),
   test: function () {
      $base = Temporaries::reserve('storage-s3-uploads');
      // ! Two parts per ListParts page, so a listing of three parts pages
      $Fake = Fake::start($base, parts: 2);

      try {
         $S3 = new S3('media', $Fake->configure());
         $Uploads = $S3->Uploads;
         $origin = "http://127.0.0.1:{$Fake->port}";
         $path = 'videos/clip one.mp4';
         $Fake->seed('media/private.txt', 'private');

         yield assert(
            assertion: $Uploads instanceof Uploads && $S3->Uploads === $Uploads && Uploads::PARTS === 10000,
            description: 'the disk exposes one handle, `$S3->Uploads`'
         );

         // # create()
         $id = $Uploads->create($path, ['type' => 'video/mp4', 'meta' => ['owner' => '7']]);
         $Open = $Fake->list();
         yield assert(
            assertion: is_string($id) === true && $id !== '' && $S3->error === ''
               && count($Open) === 1
               && $Open[0]['id'] === $id
               && $Open[0]['key'] === 'media/videos/clip one.mp4'
               && $Open[0]['type'] === 'video/mp4'
               && $Open[0]['meta'] === ['owner' => '7'],
            description: 'create() opens an upload under the root prefix, with the type and metadata the server chose, and returns its id'
         );
         $id = (string) $id;
         $Log = $Fake->log();
         yield assert(
            assertion: count($Log) === 1 && $Log[0]['operation'] === 'CreateMultipartUpload' && $Log[0]['auth'] === 'header' && $Log[0]['status'] === 200,
            description: 'one header-auth request'
         );

         // # sign() — a URL per part, no request
         $URLs = [];
         foreach ([1, 2, 3] as $number) {
            $URLs[$number] = (string) $Uploads->sign($path, $id, $number, 300);
         }
         yield assert(
            assertion: str_starts_with($URLs[1], "{$origin}/test-bucket/media/videos/clip%20one.mp4?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=") === true
               && preg_match('#&X-Amz-Expires=300&X-Amz-SignedHeaders=host&partNumber=1&uploadId=' . str_replace('~', '\~', rawurlencode($id)) . '&X-Amz-Signature=[0-9a-f]{64}$#D', $URLs[1]) === 1
               && str_contains($URLs[3], '&partNumber=3&uploadId=') === true
               && str_contains($URLs[1], Fake::SECRET) === false
               && $S3->error === ''
               && count($Fake->log()) === 1,
            description: 'sign() presigns the PUT of one part of that upload — key, part number and upload id in the signed query — without a request'
         );

         // # The uploader: bare PUTs, out of order, with no credentials
         $bytes = [1 => str_repeat('a', 5000), 2 => str_repeat('b', 5000), 3 => 'tail'];
         $answered = [];
         foreach ([3, 1, 2] as $number) {
            [$status, $headers] = fake_send('PUT', $URLs[$number], $bytes[$number], ['Content-Type' => 'text/html']);
            $answered[$number] = [$status, $headers['etag'] ?? ''];
         }
         yield assert(
            assertion: $answered === [
               3 => [200, '"' . md5($bytes[3]) . '"'],
               1 => [200, '"' . md5($bytes[1]) . '"'],
               2 => [200, '"' . md5($bytes[2]) . '"'],
            ]
               && array_values(array_filter($Fake->log(), static fn (array $Request): bool => $Request['operation'] === 'UploadPart' && $Request['auth'] !== 'query')) === [],
            description: 'each part is accepted from a bare PUT (query auth) and answers its ETag'
         );

         // # What a part URL does not authorize
         yield assert(
            assertion: fake_send('GET', $URLs[1])[0] === 403
               && fake_send('DELETE', $URLs[1])[0] === 403
               && fake_send('POST', $URLs[1], '<CompleteMultipartUpload/>')[0] === 403
               && fake_send('PUT', str_replace('partNumber=1&', 'partNumber=4&', $URLs[1]), 'x')[0] === 403
               && fake_send('PUT', str_replace('/videos/clip%20one.mp4?', '/private.txt?', $URLs[1]), 'x')[0] === 403
               && fake_send('PUT', (string) preg_replace('#&partNumber=1&uploadId=[^&]+#', '', $URLs[1]), 'x')[0] === 403
               && fake_send('PUT', $URLs[1], '', ['x-amz-copy-source' => '/test-bucket/media/private.txt'])[0] === 403
               && $Fake->fetch('media/private.txt') === 'private'
               && $Fake->fetch('media/videos/clip one.mp4') === null,
            description: 'a part URL is that part of that upload: no list, abort or complete, no other part or key, no whole-object PUT, no copy from another object'
         );

         // # list() — what the store holds, across pages
         $before = count($Fake->log());
         $Parts = $Uploads->list($path, $id);
         yield assert(
            assertion: $Parts === [
               1 => ['etag' => '"' . md5($bytes[1]) . '"', 'size' => 5000],
               2 => ['etag' => '"' . md5($bytes[2]) . '"', 'size' => 5000],
               3 => ['etag' => '"' . md5($bytes[3]) . '"', 'size' => 4],
            ] && $S3->error === '',
            description: 'list() returns every part keyed by number, ascending, with the ETag and the size the store measured'
         );
         $Pages = array_values(array_filter(array_slice($Fake->log(), $before), static fn (array $Request): bool => $Request['operation'] === 'ListParts'));
         yield assert(
            assertion: count($Pages) === 2
               && str_contains($Pages[0]['target'], 'part-number-marker') === false
               && str_contains($Pages[1]['target'], 'part-number-marker=2') === true
               && array_column($Pages, 'auth') === ['header', 'header'],
            description: 'the listing follows the part-number marker through every page'
         );

         // # A part sent again replaces the first one (a retry, a resume)
         $bytes[2] = str_repeat('B', 5000);
         fake_send('PUT', $URLs[2], $bytes[2]);
         $Parts = (array) $Uploads->list($path, $id);
         yield assert(
            assertion: ($Parts[2] ?? []) === ['etag' => '"' . md5($bytes[2]) . '"', 'size' => 5000] && array_keys($Parts) === [1, 2, 3],
            description: 'a part uploaded again replaces the earlier one in the listing'
         );

         // # complete() — in any key order
         /** @var array<int,array{etag:string,size:int}> $Parts */
         yield assert(
            assertion: $Uploads->complete($path, $id, array_reverse($Parts, true)) === true && $S3->error === '',
            description: 'complete() joins the listed parts'
         );
         $Stored = $Fake->describe('media/videos/clip one.mp4');
         yield assert(
            assertion: $Fake->fetch('media/videos/clip one.mp4') === "{$bytes[1]}{$bytes[2]}{$bytes[3]}"
               && ($Stored['type'] ?? '') === 'video/mp4'
               && ($Stored['meta'] ?? []) === ['owner' => '7']
               && $Fake->list() === [],
            description: 'the object is the parts in ascending order, with the type and metadata set at create() — not the uploader\'s Content-Type'
         );
         $Completions = array_values(array_filter($Fake->log(), static fn (array $Request): bool => $Request['operation'] === 'CompleteMultipartUpload' && $Request['auth'] === 'header'));
         yield assert(
            assertion: count($Completions) === 1 && $Completions[0]['body'] === '<CompleteMultipartUpload>'
               . '<Part><PartNumber>1</PartNumber><ETag>"' . md5($bytes[1]) . '"</ETag></Part>'
               . '<Part><PartNumber>2</PartNumber><ETag>"' . md5($bytes[2]) . '"</ETag></Part>'
               . '<Part><PartNumber>3</PartNumber><ETag>"' . md5($bytes[3]) . '"</ETag></Part>'
               . '</CompleteMultipartUpload>',
            description: 'the completion lists the parts ascending whatever order they were passed in'
         );

         // # After completion the upload is gone
         yield assert(
            assertion: $Uploads->list($path, $id) === false
               && $S3->error === 'S3 list parts /test-bucket/media/videos/clip%20one.mp4: HTTP 404'
               && $Uploads->complete($path, $id, $Parts) === false
               && $S3->error === 'S3 complete multipart /test-bucket/media/videos/clip%20one.mp4: HTTP 404'
               && fake_send('PUT', $URLs[1], 'late')[0] === 404
               && $Uploads->abort($path, $id) === true && $S3->error === '',
            description: 'a completed upload cannot be listed, completed again or fed; aborting it counts as aborted'
         );

         // # complete() from what the uploader reported, sizes unknown
         $second = (string) $Uploads->create('docs/report.pdf');
         [, $headers] = fake_send('PUT', (string) $Uploads->sign('docs/report.pdf', $second, 1, 60), 'one-');
         $first = $headers['etag'] ?? '';
         [, $headers] = fake_send('PUT', (string) $Uploads->sign('docs/report.pdf', $second, 7, 60), 'seven');
         yield assert(
            assertion: $Uploads->complete('docs/report.pdf', $second, [7 => ['etag' => trim($headers['etag'] ?? '', '"')], 1 => ['etag' => $first]]) === true
               && $Fake->fetch('media/docs/report.pdf') === 'one-seven'
               && ($Fake->describe('media/docs/report.pdf')['type'] ?? '') === 'application/octet-stream',
            description: 'complete() takes `[number => [etag]]` as the uploader reported it — sparse numbers, quoted or not — and create() defaults the type'
         );

         // # A failed completion leaves the upload open
         $third = (string) $Uploads->create('docs/draft.txt');
         fake_send('PUT', (string) $Uploads->sign('docs/draft.txt', $third, 1, 60), 'draft');
         yield assert(
            assertion: $Uploads->complete('docs/draft.txt', $third, [1 => ['etag' => '"00000000000000000000000000000000"']]) === false
               && $S3->error === 'S3 complete multipart /test-bucket/media/docs/draft.txt: HTTP 400'
               && $Uploads->complete('docs/draft.txt', $third, [1 => ['etag' => 'x'], 2 => ['etag' => 'y']]) === false
               && count($Fake->list()) === 1
               && $Fake->fetch('media/docs/draft.txt') === null,
            description: 'a completion the store refuses (a wrong ETag, a part never uploaded) is false and the upload stays open'
         );

         // # An upload id only works with its own key
         yield assert(
            assertion: fake_send('PUT', (string) $Uploads->sign('docs/another.txt', $third, 1, 60), 'x')[0] === 404
               && $Uploads->list('docs/another.txt', $third) === false
               && $Uploads->complete('docs/another.txt', $third, (array) $Uploads->list('docs/draft.txt', $third)) === false
               && $Fake->fetch('media/docs/another.txt') === null,
            description: 'the id of one object\'s upload does not write, list or complete under another path'
         );

         // # abort()
         yield assert(
            assertion: $Uploads->abort('docs/draft.txt', $third) === true && $S3->error === ''
               && $Fake->list() === []
               && $Fake->fetch('media/docs/draft.txt') === null
               && $Uploads->abort('docs/draft.txt', $third) === true
               && $Uploads->abort('docs/draft.txt', 'never-issued') === true,
            description: 'abort() releases the parts; an upload the store no longer knows counts as aborted'
         );

         // # The root prefix jail
         $escaped = (string) $Uploads->create('../../escape.bin');
         yield assert(
            assertion: ($Fake->list()[0]['key'] ?? '') === 'media/escape.bin'
               && str_starts_with((string) $Uploads->sign('../../escape.bin', $escaped, 1, 60), "{$origin}/test-bucket/media/escape.bin?") === true
               && $Uploads->list('../../escape.bin', $escaped) === []
               && $Uploads->abort('../../escape.bin', $escaped) === true,
            description: 'every step resolves the path inside the root prefix; an upload with no parts lists as empty'
         );

         // # Only the tampering probes above were refused by the signature check
         yield assert(
            assertion: array_filter($Fake->log(), static fn (array $Request): bool => $Request['status'] === 403 && $Request['auth'] === 'header') === [],
            description: 'no request the driver signed was refused'
         );
      }
      finally {
         $Fake->stop();
         wipe($base);
      }
   }
);
