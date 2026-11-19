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


use const E_ALL;
use function array_diff;
use function array_slice;
use function assert;
use function error_reporting;
use function fake_skip;
use function implode;
use function ini_get;
use function ini_set;
use function print_r;
use function restore_error_handler;
use function set_error_handler;
use function str_contains;
use function wipe;
use ErrorException;
use ReflectionParameter;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use Throwable;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ABI\Resources\Storage;
use Bootgly\ABI\Resources\Storage\Drivers\S3;
use Bootgly\ABI\Resources\Storage\tests\fixtures\S3 as Fake;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


require_once __DIR__ . '/disk.php';
require_once __DIR__ . '/fixtures/S3.php';


/**
 * `S3->send()` carries the signed headers of a request: the `Authorization` signature and
 * the session token (`x-amz-security-token`). Whatever throws under it — here the parse of
 * a chunked answer whose chunk size is not hexadecimal, a deprecation the framework turns
 * into an ErrorException — throws with that frame on the stack. The local fake answers so
 * while traces hold every argument, uncapped: `send()` holds a `SensitiveParameterValue`
 * and no renderer of the trace shows the token or the signature; an unmarked frame given
 * the same token shows it in every renderer.
 */
return new Test(
   description: 'Storage(S3) fake: the signed headers of a request stay out of the stack trace of a failure under it',
   skip: fake_skip(),
   test: function () {
      $base = Temporaries::reserve('storage-s3-send-trace');
      $Fake = Fake::start($base);

      // ! Traces at their fullest — every argument, no string cap
      $ignore = ini_get('zend.exception_ignore_args');
      $length = ini_get('zend.exception_string_param_max_len');
      $ignored = ini_set('zend.exception_ignore_args', '0');
      $capped = ini_set('zend.exception_string_param_max_len', '1000000');
      // ! A deprecation is an ErrorException, as under the framework's error handler
      $reporting = error_reporting(E_ALL);
      set_error_handler(static function (int $level, string $message, string $file, int $line): never {
         throw new ErrorException($message, 0, $level, $file, $line);
      });
      try {
         yield assert(
            assertion: $ignored !== false && $capped !== false,
            description: 'This spec raises traces to every argument, uncapped'
         );

         // ! The renderers of a caught trace
         $Dumper = new Dumper('plain');
         // ! The trace's own frames: every frame up to the last call made from this file — the
         //   runner's frames below it hold the suite state, which a full run makes unbounded
         $Frames = static function (Throwable $Throwable): array {
            $Trace = $Throwable->getTrace();
            $last = 0;
            foreach ($Trace as $index => $Frame) {
               if (($Frame['file'] ?? '') === __FILE__) {
                  $last = $index;
               }
            }

            return array_slice($Trace, 0, $last + 1);
         };
         $Renderers = [
            'print_r' => static fn (Throwable $Throwable): string => print_r($Frames($Throwable), true),
            'getTraceAsString' => static fn (Throwable $Throwable): string => $Throwable->getTraceAsString(),
            'Dumper' => static fn (Throwable $Throwable): string => $Dumper->dump($Frames($Throwable)),
         ];
         $token = 'SYNTH-S3-SESSION-TOKEN-0123456789';

         // # Positive control: an unmarked frame given the token shows it everywhere
         $Control = null;
         try {
            (static function (string $secret): never {
               throw new RuntimeException('Unmarked control.');
            })($token);
         }
         catch (RuntimeException $Exception) {
            $Control = $Exception;
         }
         $blind = [];
         foreach ($Renderers as $renderer => $Renderer) {
            if (str_contains($Renderer($Control), $token) === false) {
               $blind[] = $renderer;
            }
         }
         $unseen = implode(', ', $blind);
         yield assert(
            assertion: $blind === [],
            description: "An unmarked frame shows the token in every renderer (blind: [{$unseen}])"
         );

         // # A listing answered with a chunk size that is not hexadecimal
         $Fake->inject(
            'ListObjectsV2',
            200,
            raw: "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\nzz\r\nabc\r\n0\r\n\r\n"
         );
         $Storage = new Storage(['default' => 'remote', 'disks' => ['remote' => ['driver' => 's3', ...$Fake->configure(['token' => $token])]]]);
         $Thrown = null;
         try {
            $Storage->list();
         }
         catch (Throwable $Throwable) {
            $Thrown = $Throwable;
         }

         $redacted = [];
         $leaks = [];
         if ($Thrown !== null) {
            foreach ($Thrown->getTrace() as $frame) {
               foreach ($frame['args'] ?? [] as $argument) {
                  if ($argument instanceof SensitiveParameterValue) {
                     $redacted[] = ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'];
                  }
               }
            }
            foreach ($Renderers as $renderer => $Renderer) {
               $trace = $Renderer($Thrown);
               if (str_contains($trace, $token) === true || str_contains($trace, 'Signature=') === true) {
                  $leaks[] = $renderer;
               }
            }
         }
         $Log = $Fake->log();
         $unmarked = implode(', ', array_diff([S3::class . '->send'], $redacted));
         $leaked = implode(', ', $leaks);
         yield assert(
            assertion: $Thrown !== null
               && ($Log[0]['operation'] ?? '') === 'ListObjectsV2'
               && ($Log[0]['headers']['x-amz-security-token'] ?? '') === $token
               && $unmarked === ''
               && $leaks === [],
            description: 'A signed listing that fails under send(): send() redacts the signed headers '
               . "(unmarked: [{$unmarked}]) and no renderer shows the token or the signature (leaked: [{$leaked}])"
         );
      }
      finally {
         restore_error_handler();
         error_reporting($reporting);
         ini_set('zend.exception_ignore_args', (string) $ignore);
         ini_set('zend.exception_string_param_max_len', (string) $length);
         $Fake->stop();
         wipe($base);
      }

      // # The attribute, pinned
      yield assert(
         assertion: (new ReflectionParameter([S3::class, 'send'], 'headers'))->getAttributes(SensitiveParameter::class) !== [],
         description: 'S3::send() marks the signed headers as sensitive'
      );
   }
);
