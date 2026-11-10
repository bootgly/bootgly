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
use function array_count_values;
use function array_filter;
use function array_values;
use function assert;
use function count;
use function fake_skip;
use function md5;
use function preg_match;
use function source;
use function str_repeat;
use function substr;
use function wipe;

use Bootgly\ABI\Resources\Storage\Drivers\S3;
use Bootgly\ABI\Resources\Storage\tests\fixtures\S3 as Fake;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


require_once __DIR__ . '/disk.php';
require_once __DIR__ . '/fixtures/S3.php';


/**
 * `write()` of an object larger than one part (16 MiB) goes through the driver's own
 * Multipart Upload: create, parts pumped in parallel, complete — and an abort on any
 * failure, so no orphan upload is left behind. Pinned against the local fake before the
 * public multipart handle shared these steps.
 */
return new Test(
   description: 'Storage(S3) fake: write() of a large object is a Multipart Upload that aborts on failure',
   skip: fake_skip(),
   test: function () {
      $base = Temporaries::reserve('storage-s3-multipart-write');
      $Fake = Fake::start($base);

      try {
         $S3 = new S3('', $Fake->configure());
         $part = 16 * 1024 * 1024;

         // # Three parts: the two probed ones, then one streamed from the source
         $bytes = substr(str_repeat('bootgly-multipart-', (int) (2 * $part / 18) + 2), 0, 2 * $part + 5);
         $checksum = md5($bytes);
         $ETags = [md5(substr($bytes, 0, $part)), md5(substr($bytes, $part, $part)), md5(substr($bytes, 2 * $part))];
         $Source = source($bytes);
         unset($bytes);

         yield assert(
            assertion: $S3->write('big/video.bin', $Source, ['type' => 'video/mp4', 'meta' => ['title' => 'Clip']]) === true
               && $S3->error === '',
            description: 'write() uploads an object of three parts'
         );
         unset($Source);
         $Stored = $Fake->describe('big/video.bin');
         yield assert(
            assertion: md5((string) $Fake->fetch('big/video.bin')) === $checksum
               && ($Stored['type'] ?? '') === 'video/mp4'
               && ($Stored['meta'] ?? []) === ['title' => 'Clip'],
            description: 'the parts are joined in order and the object keeps its type and metadata'
         );
         $Log = $Fake->log();
         $Complete = array_values(array_filter($Log, static fn (array $Request): bool => $Request['operation'] === 'CompleteMultipartUpload'));
         yield assert(
            assertion: array_count_values(array_column($Log, 'operation')) === [
               'CreateMultipartUpload' => 1,
               'UploadPart' => 3,
               'CompleteMultipartUpload' => 1,
            ]
               && array_filter(array_column($Log, 'status'), static fn (int $status): bool => $status !== 200) === []
               && $Fake->list() === [],
            description: 'one create, three parts, one complete — all accepted, no upload left open'
         );
         yield assert(
            assertion: ($Complete[0]['body'] ?? '') === '<CompleteMultipartUpload>'
               . "<Part><PartNumber>1</PartNumber><ETag>\"{$ETags[0]}\"</ETag></Part>"
               . "<Part><PartNumber>2</PartNumber><ETag>\"{$ETags[1]}\"</ETag></Part>"
               . "<Part><PartNumber>3</PartNumber><ETag>\"{$ETags[2]}\"</ETag></Part>"
               . '</CompleteMultipartUpload>'
               && ($Complete[0]['headers']['content-type'] ?? '') === 'application/xml',
            description: 'the completion lists the parts ascending with the ETags the store answered'
         );

         // # Failures: two parts are enough
         $bytes = str_repeat('x', $part + 5);

         $Fake->inject('CreateMultipartUpload', 500);
         yield assert(
            assertion: $S3->write('big/create.bin', source($bytes)) === false
               && $S3->error === 'S3 create multipart /test-bucket/big/create.bin: HTTP 500'
               && $Fake->fetch('big/create.bin') === null,
            description: 'a failed create is reported and nothing is stored'
         );

         $Fake->inject('UploadPart', 500);
         yield assert(
            assertion: $S3->write('big/part.bin', source($bytes)) === false
               && preg_match('/^S3 upload part [12]: HTTP 500$/', $S3->error) === 1
               && $Fake->fetch('big/part.bin') === null
               && $Fake->list() === [],
            description: 'a failed part is reported, nothing is stored and the upload is aborted'
         );
         $Operations = array_column($Fake->log(), 'operation');
         yield assert(
            assertion: ($Operations[count($Operations) - 1] ?? '') === 'AbortMultipartUpload',
            description: 'the last request of the failed upload is its abort'
         );

         // ! S3 may answer a completion with 200 and an <Error> body
         $Fake->inject('CompleteMultipartUpload', 200, 'InternalError');
         yield assert(
            assertion: $S3->write('big/complete.bin', source($bytes)) === false
               && $S3->error === 'S3 complete multipart /test-bucket/big/complete.bin: HTTP 200'
               && $Fake->fetch('big/complete.bin') === null
               && $Fake->list() === [],
            description: 'a completion answered 200 with an Error body is a failure, and the upload is aborted'
         );

         yield assert(
            assertion: array_filter($Fake->log(), static fn (array $Request): bool => $Request['status'] === 403) === [],
            description: 'no request was refused by the signature check'
         );
      }
      finally {
         $Fake->stop();
         wipe($base);
      }
   }
);
