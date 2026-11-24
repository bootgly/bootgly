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
use function is_string;
use function md5;
use function str_repeat;
use function wipe;
use LogicException;

use Bootgly\ABI\Resources\Storage\Drivers\S3;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Failure\Kinds;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Uploads;
use Bootgly\ABI\Resources\Storage\tests\fixtures\S3 as Fake;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


require_once __DIR__ . '/disk.php';
require_once __DIR__ . '/fixtures/S3.php';


/**
 * `$Uploads->put()` — the server sends a part itself (UploadPart) and gets its ETag back — end to
 * end against the local fake: a whole upload of server-sent parts, then every way a part fails,
 * each carried on `$S3->Failure` with its status, the store's code and its kind.
 */
return new Test(
   description: 'Storage(S3) fake: Uploads::put() sends one part and returns its ETag; its failures carry status, code and kind',
   skip: fake_skip(),
   test: function () {
      $base = Temporaries::reserve('storage-s3-uploads-put');
      $Fake = Fake::start($base);

      try {
         $S3 = new S3('media', $Fake->configure());
         $Uploads = $S3->Uploads;
         $path = 'videos/server side.bin';

         // # A whole upload of server-sent parts
         $id = (string) $Uploads->create($path, ['type' => 'application/octet-stream']);
         $bytes = [1 => str_repeat('a', 6000), 2 => str_repeat('b', 6000), 3 => 'tail'];
         $ETags = [];
         foreach ([2, 3, 1] as $number) {
            $ETags[$number] = $Uploads->put($path, $id, $number, $bytes[$number]);
         }
         yield assert(
            assertion: $ETags === [
               2 => '"' . md5($bytes[2]) . '"',
               3 => '"' . md5($bytes[3]) . '"',
               1 => '"' . md5($bytes[1]) . '"',
            ]
               && $S3->error === ''
               && $S3->Failure === null,
            description: 'put() uploads a part in any order and returns its ETag as the store sent it (quoted)'
         );
         $Parts = [];
         foreach ($ETags as $number => $ETag) {
            $Parts[$number] = ['etag' => (string) $ETag];
         }
         yield assert(
            assertion: $Uploads->complete($path, $id, $Parts) === true
               && $Fake->fetch('media/videos/server side.bin') === "{$bytes[1]}{$bytes[2]}{$bytes[3]}"
               && $Fake->list() === [],
            description: 'the ETags put() returned complete the upload, the parts joined in order'
         );
         $Log = array_values(array_column($Fake->log(), 'operation'));
         $Puts = array_values(array_filter($Fake->log(), static fn (array $Request): bool => $Request['operation'] === 'UploadPart'));
         yield assert(
            assertion: count($Puts) === 3
               && $Puts[0]['auth'] === 'header'
               && $Puts[0]['status'] === 200
               && $Puts[0]['length'] === 6000
               && $Log[count($Log) - 1] === 'CompleteMultipartUpload',
            description: 'each part is one signed (header auth) UploadPart carrying the bytes'
         );

         // # An empty part is sent as it is (a last part may be empty)
         $id = (string) $Uploads->create($path);
         yield assert(
            assertion: $Uploads->put($path, $id, 1, '') === '"' . md5('') . '"',
            description: 'an empty body is a part too'
         );

         // # The store refuses: NoSuchUpload, with its status, code and kind
         $Uploads->abort($path, $id);
         yield assert(
            assertion: $Uploads->put($path, $id, 1, 'x') === false
               && $S3->error === 'S3 upload part 1: HTTP 404'
               && $S3->Failure?->status === 404
               && $S3->Failure->code === 'NoSuchUpload'
               && $S3->Failure->kind === Kinds::Refusal
               && $S3->Failure->message === 'S3 upload part 1: HTTP 404',
            description: 'a part of an upload the store no longer holds is a Refusal 404 NoSuchUpload'
         );

         // # A completion that names a part the store does not hold: InvalidPart
         $id = (string) $Uploads->create($path);
         $ETag = (string) $Uploads->put($path, $id, 1, 'one');
         yield assert(
            assertion: $Uploads->complete($path, $id, [1 => ['etag' => $ETag], 2 => ['etag' => '"' . md5('two') . '"']]) === false
               && $S3->Failure?->status === 400
               && $S3->Failure->code === 'InvalidPart'
               && $S3->Failure->kind === Kinds::Refusal,
            description: 'complete() with a part that was never put is a Refusal 400 InvalidPart'
         );

         // # Transport: the store drops the connection
         $Fake->inject('UploadPart', 0);
         yield assert(
            assertion: $Uploads->put($path, $id, 2, 'two') === false
               && $S3->error === 'S3 upload part 2: HTTP 0'
               && $S3->Failure?->status === 0
               && $S3->Failure->code === ''
               && $S3->Failure->kind === Kinds::Transport,
            description: 'a dropped connection is a Transport failure with no status'
         );
         $Fake->inject('UploadPart', 503, 'SlowDown', 'Please reduce your request rate.');
         $retried = $Uploads->put($path, $id, 2, 'two');
         yield assert(
            assertion: $retried === false
               && $S3->Failure?->status === 503
               && $S3->Failure->code === 'SlowDown'
               && $S3->Failure->kind === Kinds::Refusal
               && $Uploads->put($path, $id, 2, 'two') === '"' . md5('two') . '"'
               && $S3->Failure === null,
            description: 'a 503 SlowDown is a Refusal a caller may retry: the same part sent again succeeds and clears the failure'
         );

         // # Protocol: a 200 with no ETag, or one complete() could not send back
         $Fake->inject('UploadPart', 200, raw: "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
         $Fake->inject('UploadPart', 200, raw: "HTTP/1.1 200 OK\r\nETag: \"\"\r\nContent-Length: 0\r\n\r\n");
         yield assert(
            assertion: $Uploads->put($path, $id, 3, 'three') === false
               && $S3->error === 'S3 upload part 3: missing or malformed ETag'
               && $S3->Failure?->kind === Kinds::Protocol
               && $S3->Failure->status === 200
               && $Uploads->put($path, $id, 3, 'three') === false
               && $S3->Failure?->kind === Kinds::Protocol,
            description: 'a 200 with no ETag, or an empty one, is a Protocol failure'
         );

         // # The driver refuses before sending: Refusal, status 0
         $before = count($Fake->log());
         $refusals = [
            [fn () => $Uploads->put($path, 'bad id', 1, 'x'), 'S3 upload part: invalid upload id'],
            [fn () => $Uploads->put($path, $id, 0, 'x'), 'S3 upload part: the part number is 1..' . Uploads::PARTS],
            [fn () => $Uploads->put($path, $id, Uploads::PARTS + 1, 'x'), 'S3 upload part: the part number is 1..' . Uploads::PARTS],
            [fn () => $Uploads->put('', $id, 1, 'x'), 'S3 upload part: an object path is required'],
            [fn () => $Uploads->put('/', $id, 1, 'x'), 'S3 upload part: an object path is required'],
         ];
         $refused = true;
         foreach ($refusals as [$Call, $message]) {
            $refused = $refused
               && $Call() === false
               && $S3->error === $message
               && $S3->Failure?->status === 0
               && $S3->Failure->code === ''
               && $S3->Failure->kind === Kinds::Refusal
               && $S3->Failure->message === $message;
         }
         yield assert(
            assertion: $refused === true && count($Fake->log()) === $before,
            description: 'an invalid upload id, a part number outside 1..10000 or the disk root is refused without a request'
         );

         // # A handle built without the step
         $Bare = new Uploads(static fn () => '', static fn () => '', static fn () => [], static fn () => true, static fn () => true);
         $thrown = null;
         try {
            $Bare->put('a.bin', 'id', 1, 'x');
         }
         catch (LogicException $Exception) {
            $thrown = $Exception->getMessage();
         }
         yield assert(
            assertion: is_string($thrown) === true && $thrown === 'S3 Uploads: this handle was built without a put() step.',
            description: 'put() on a handle built without its step is a programming error (LogicException)'
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
