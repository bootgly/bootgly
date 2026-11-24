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


use function array_shift;
use function assert;
use function class_exists;
use function fopen;
use function in_array;
use function rewind;
use function str_repeat;
use function stream_get_contents;
use function stream_get_wrappers;
use function stream_wrapper_register;
use function strlen;
use function substr;
use ReflectionMethod;

use Bootgly\ABI\Resources\Storage\Drivers\S3;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Failure\Kinds;
use Bootgly\ACI\Tests\Suite\Test;


if (class_exists(S3HeadStream::class, false) === false) {
   /**
    * A socket that hands out the answer in the pieces queued (`$pieces`), or — with `$endless` —
    * a head that never ends, counting the bytes it gave (`$served`).
    */
   class S3HeadStream
   {
      // * Data
      /** @var array<int,string> */
      public static array $pieces = [];
      public static bool $endless = false;
      public mixed $context;

      // * Metadata
      public static int $served = 0;


      public function stream_open (string $path, string $mode, int $options, null|string &$opened_path): bool
      {
         // :
         return true;
      }

      public function stream_read (int $count): string
      {
         // ? A head that never ends: 8 KiB of a header value at a time, up to 4 MiB
         if (self::$endless === true) {
            if (self::$served >= 4 * 1024 * 1024) {
               return '';
            }
            self::$served += 8192;

            return str_repeat('a', 8192);
         }

         // :
         return array_shift(self::$pieces) ?? '';
      }

      public function stream_eof (): bool
      {
         // :
         return self::$endless === true ? self::$served >= 4 * 1024 * 1024 : self::$pieces === [];
      }

      public function stream_set_option (int $option, int $first, null|int $second): bool
      {
         // :
         return false;
      }
   }
}


/**
 * The end of an answer head is found wherever the socket splits it (a TLS record ends anywhere),
 * and a head that never ends stops being read past 64 KiB.
 */
return new Test(
   description: 'Storage(S3) offline: the answer head is found across reads, and bounded',
   test: function () {
      if (in_array('s3head', stream_get_wrappers(), true) === false) {
         stream_wrapper_register('s3head', S3HeadStream::class);
      }
      $Disk = static fn (): S3 => new S3('', [
         'bucket' => 'test-bucket',
         'region' => 'us-east-1',
         'key' => 'AKIDEXAMPLE',
         'secret' => 'not-a-secret',
         'endpoint' => 'http://127.0.0.1:1',
         'insecure' => true,
      ]);
      $Drain = new ReflectionMethod(S3::class, 'drain');

      // # The blank line split across two reads, at each of its bytes
      $head = "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\n";
      $bodies = [];
      foreach ([0, 1, 2, 3, 4] as $cut) {
         $at = strlen($head) - 4 + $cut;
         S3HeadStream::$endless = false;
         $rest = substr($head, $at);
         S3HeadStream::$pieces = [substr($head, 0, $at), "{$rest}hello"];
         $Sink = fopen('php://memory', 'r+');
         [$code] = $Drain->invoke($Disk(), fopen('s3head://answer', 'r'), $Sink, null);
         rewind($Sink);
         $bodies[] = [$code, stream_get_contents($Sink)];
      }
      yield assert(
         assertion: $bodies === [[200, 'hello'], [200, 'hello'], [200, 'hello'], [200, 'hello'], [200, 'hello']],
         description: 'a head whose blank line is split across two reads, anywhere, is still found'
      );

      // # A head that never ends
      S3HeadStream::$endless = true;
      S3HeadStream::$served = 0;
      $S3 = $Disk();
      $Sink = fopen('php://memory', 'r+');
      [$code] = $Drain->invoke($S3, fopen('s3head://answer', 'r'), $Sink, null);
      S3HeadStream::$endless = false;
      rewind($Sink);
      yield assert(
         assertion: $code === 0
            && S3HeadStream::$served <= 80 * 1024
            && stream_get_contents($Sink) === ''
            && $S3->Failure?->kind === Kinds::Protocol
            && $S3->error === 'S3 GET: the answer head is too large to be an S3 answer',
         description: 'a head that never ends is read no further than 64 KiB (and one read), then fails as Protocol'
      );
   }
);
