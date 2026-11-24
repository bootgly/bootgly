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
use function class_exists;
use function fake_skip;
use function fopen;
use function in_array;
use function rewind;
use function source;
use function stream_get_contents;
use function stream_get_wrappers;
use function stream_wrapper_register;
use function strlen;
use function wipe;
use Fiber;
use RuntimeException;

use Bootgly\ABI\Resources\Storage\Drivers\S3;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Failure\Kinds;
use Bootgly\ABI\Resources\Storage\tests\fixtures\S3 as Fake;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


require_once __DIR__ . '/disk.php';
require_once __DIR__ . '/fixtures/S3.php';


if (class_exists(S3FailuresStream::class, false) === false) {
   /**
    * A stream that hands control back to the scheduler in the middle of a call (`suspend`: the
    * first write into it suspends the current Fiber) or throws out of it (`throw`: every read).
    */
   class S3FailuresStream
   {
      // * Data
      public static string $mode = '';
      public static string $written = '';
      public mixed $context;

      // * Metadata
      private bool $suspended = false;


      public function stream_open (string $path, string $mode, int $options, null|string &$opened_path): bool
      {
         // :
         return true;
      }

      public function stream_read (int $count): string
      {
         // ?
         if (self::$mode === 'throw') {
            throw new RuntimeException('source failed');
         }

         // :
         return '';
      }

      public function stream_write (string $data): int
      {
         // ? Suspended once, in the middle of the call
         if (self::$mode === 'suspend' && $this->suspended === false && Fiber::getCurrent() !== null) {
            $this->suspended = true;
            Fiber::suspend();
         }
         self::$written .= $data;

         // :
         return strlen($data);
      }

      public function stream_eof (): bool
      {
         // :
         return true;
      }

      /** @return array<string,mixed> */
      public function stream_stat (): array
      {
         // :
         return [];
      }
   }
}


/**
 * `$S3->Failure` is per disk and per Fiber: requests served concurrently on one driver — each
 * in its own Fiber, suspended in the middle of a call — each read their own failure; a call
 * that throws does not leave the next one reading an older failure.
 */
return new Test(
   description: 'Storage(S3) fake: $Failure belongs to the disk and the Fiber that made the call',
   skip: fake_skip(),
   test: function () {
      $base = Temporaries::reserve('storage-s3-failures-fibers');
      $Fake = Fake::start($base);
      $scheme = 'bootgly-s3-failures';
      if (in_array($scheme, stream_get_wrappers(), true) === false) {
         stream_wrapper_register($scheme, S3FailuresStream::class);
      }

      try {
         $S3 = new S3('', $Fake->configure());
         $Fake->seed('a.txt', 'alpha');

         // # Two Fibers on one disk, the first suspended in the middle of its read
         S3FailuresStream::$mode = 'suspend';
         S3FailuresStream::$written = '';
         $Reader = new Fiber(function () use ($S3, $scheme): array {
            $Sink = fopen("{$scheme}://sink", 'w');
            $read = $S3->read('a.txt', $Sink);

            return [$read, $S3->Failure, $S3->error];
         });
         $Deleter = new Fiber(function () use ($S3, $Fake): array {
            $Fake->inject('DeleteObject', 500, 'InternalError');
            $deleted = $S3->delete('a.txt');
            Fiber::suspend();

            return [$deleted, $S3->Failure, $S3->error];
         });
         $Reader->start();
         $suspended = $Reader->isSuspended();
         $Deleter->start();
         // ! The main context makes its own call in between
         $missing = $S3->measure('missing.bin');
         $Main = $S3->Failure;
         $Reader->resume();
         $Deleter->resume();
         [$read, $Read] = $Reader->getReturn();
         [$deleted, $Deleted] = $Deleter->getReturn();
         yield assert(
            assertion: $suspended === true
               && $read === true
               && S3FailuresStream::$written === 'alpha'
               && $Read === null
               && $deleted === false
               && $Deleted?->status === 500
               && $Deleted->code === 'InternalError'
               && $missing === false
               && $Main?->status === 404
               && $S3->Failure?->status === 404,
            description: 'a read suspended mid-call ends clean though another Fiber failed meanwhile; each Fiber and the main context read their own failure'
         );

         // # A Fiber starts clean, whatever the main context left
         $Fresh = new Fiber(fn () => $S3->Failure);
         $Fresh->start();
         yield assert(
            assertion: $S3->Failure?->status === 404 && $Fresh->getReturn() === null,
            description: 'a Fiber that made no call yet has no failure'
         );

         // # A Fiber that keeps running: each outermost call starts clean
         S3FailuresStream::$mode = '';
         $Worker = new Fiber(function () use ($S3, $Fake): array {
            $Seen = [];
            $Fake->inject('HeadObject', 403, 'AccessDenied');
            $S3->measure('a.txt');
            $Seen[] = $S3->Failure?->status;
            Fiber::suspend();
            $S3->measure('a.txt');
            $Seen[] = $S3->Failure?->status;

            return $Seen;
         });
         $Worker->start();
         $Worker->resume();
         yield assert(
            assertion: $Worker->getReturn() === [403, null],
            description: 'a Fiber reused for another request does not see the failure of its last one'
         );

         // # A call that throws, then a call that fails, then one that works
         S3FailuresStream::$mode = 'throw';
         $thrown = false;
         try {
            $S3->write('b.txt', fopen("{$scheme}://source", 'r'));
         }
         catch (RuntimeException) {
            $thrown = true;
         }
         S3FailuresStream::$mode = '';
         $Fake->inject('DeleteObject', 503, 'SlowDown');
         $S3->delete('a.txt');
         $Failed = $S3->Failure;
         $worked = $S3->write('b.txt', source('beta'));
         yield assert(
            assertion: $thrown === true
               && $Failed?->status === 503
               && $Failed->code === 'SlowDown'
               && $worked === true
               && $S3->Failure === null
               && $S3->error === '',
            description: 'a call that threw does not leave the next calls nested in it: each reports its own outcome'
         );

         // # Two disks in one context: each keeps its own failure
         $Other = new S3('', $Fake->configure());
         $Fake->inject('HeadObject', 403, 'AccessDenied');
         $S3->measure('a.txt');
         $Other->measure('a.txt');
         $Sink = fopen('php://memory', 'r+');
         $Other->read('a.txt', $Sink);
         rewind($Sink);
         yield assert(
            assertion: $S3->Failure?->status === 403
               && $S3->Failure->kind === Kinds::Refusal
               && $Other->Failure === null
               && stream_get_contents($Sink) === 'alpha',
            description: 'a call on one disk does not clear the failure of another disk'
         );
      }
      finally {
         S3FailuresStream::$mode = '';
         $Fake->stop();
         wipe($base);
      }
   }
);
