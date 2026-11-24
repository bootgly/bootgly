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


use function assert;
use function fake_skip;
use function fopen;
use function source;
use function str_contains;
use function wipe;
use Fiber;

use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ABI\Resources\Storage;
use Bootgly\ABI\Resources\Storage\Drivers\S3;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Failure\Kinds;
use Bootgly\ABI\Resources\Storage\tests\fixtures\S3 as Fake;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


require_once __DIR__ . '/disk.php';
require_once __DIR__ . '/fixtures/S3.php';


/**
 * A Storage event listener may call the same disk: the facade runs it through the disk's
 * `preserve()`, so the caller still reads the outcome of its own call afterwards — `$error` on
 * every driver, `$Failure` on S3 — and the listener reads the outcome of its own calls.
 */
return new Test(
   description: 'Storage: an event listener that calls the same disk leaves the caller the outcome of its own call',
   skip: fake_skip(),
   test: function () {
      $base = Temporaries::reserve('storage-events-preserve');
      $Fake = Fake::start("{$base}/fake");
      $Previous = isset(Emitter::$Instance) === true ? Emitter::$Instance : null;
      Emitter::$Instance = new Emitter();

      try {
         // # S3: a write refused, a listener that checks other objects meanwhile
         $Storage = new Storage(['default' => 's3', 'disks' => ['s3' => ['driver' => 's3', 'root' => ''] + $Fake->configure()]]);
         /** @var S3 $Disk */
         $Disk = $Storage->open();
         $Seen = [];
         Emitter::$Instance->listen(Events::Written, function (Emission $Emission) use ($Disk, &$Seen) {
            $Disk->write('audit.txt', source('written'));
            $Seen[] = [$Disk->error, $Disk->Failure];
            $Disk->measure('missing.bin');
            $Seen[] = [$Disk->error, $Disk->Failure?->status];
         });
         $Fake->inject('PutObject', 403, 'AccessDenied');
         $written = $Storage->write('a.txt', source('alpha'));
         yield assert(
            assertion: $written === false
               && $Disk->error === 'S3 PUT /test-bucket/a.txt: HTTP 403'
               && $Disk->Failure?->status === 403
               && $Disk->Failure->code === 'AccessDenied'
               && $Disk->Failure->kind === Kinds::Refusal,
            description: 'S3: after the listeners ran, the failed write still reads its own $error and $Failure'
         );
         yield assert(
            assertion: $Seen === [['', null], ['S3 HEAD /test-bucket/missing.bin: HTTP 404', 404]]
               && $Fake->fetch('audit.txt') === 'written',
            description: 'S3: inside the listener, each call of the listener reads its own outcome'
         );
         $written = $Storage->write('b.txt', source('beta'));
         yield assert(
            assertion: $written === true && $Disk->error === '' && $Disk->Failure === null,
            description: 'S3: a write that succeeds stays clean though the listener failed a call'
         );

         // # S3 inside a Fiber (a deferred response): a delete refused, a listener that fails another call
         Emitter::$Instance = new Emitter();
         $Measure = function (Emission $Emission) use ($Disk) {
            $Disk->measure('missing.bin');
         };
         Emitter::$Instance->listen(Events::Deleted, $Measure);
         Emitter::$Instance->listen(Events::Read, $Measure);
         $Fake->inject('DeleteObject', 403, 'AccessDenied');
         $Fiber = new Fiber(function () use ($Storage, $Disk): array {
            $deleted = $Storage->delete('b.txt');

            return [$deleted, $Disk->error, $Disk->Failure?->status, $Disk->Failure?->code];
         });
         $Fiber->start();
         yield assert(
            assertion: $Fiber->getReturn() === [false, 'S3 DELETE b.txt: HTTP 403', 403, 'AccessDenied']
               && $Fake->fetch('b.txt') === 'beta',
            description: 'S3 in a Fiber: after the listeners ran, the refused delete still reads its own $error and $Failure'
         );

         // # S3: a read that fails, a listener that fails another call
         $Fake->inject('GetObject', 500, 'InternalError');
         $read = $Storage->read('b.txt', fopen('php://memory', 'r+'));
         yield assert(
            assertion: $read === false
               && $Disk->error === 'S3 GET /test-bucket/b.txt: HTTP 500'
               && $Disk->Failure?->status === 500
               && $Disk->Failure->code === 'InternalError',
            description: 'S3: after the listeners ran, the failed read still reads its own $error and $Failure'
         );

         // # Local: the same for $error
         Emitter::$Instance = new Emitter();
         $Local = new Storage(['disks' => ['local' => ['driver' => 'local', 'root' => "{$base}/local"]]]);
         Emitter::$Instance->listen(Events::Read, function (Emission $Emission) use ($Local) {
            $Local->open()->write('audit.txt', source('read'));
         });
         $read = $Local->read('missing.txt', fopen('php://memory', 'r+'));
         yield assert(
            assertion: $read === false
               && str_contains($Local->open()->error, 'not found') === true
               && $Local->open()->check('audit.txt') === true,
            description: 'Local: a listener that writes leaves the failed read its $error'
         );
      }
      finally {
         if ($Previous !== null) {
            Emitter::$Instance = $Previous;
         }
         $Fake->stop();
         wipe($base);
      }
   }
);
