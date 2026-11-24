<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Tests\S3;


use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function array_column;
use function array_count_values;
use function array_filter;
use function array_map;
use function array_search;
use function array_slice;
use function array_values;
use function assert;
use function class_exists;
use function count;
use function fake_skip;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function fopen;
use function fwrite;
use function in_array;
use function is_array;
use function is_resource;
use function json_decode;
use function max;
use function microtime;
use function min;
use function rewind;
use function round;
use function str_contains;
use function str_repeat;
use function stream_get_wrappers;
use function stream_set_blocking;
use function stream_socket_pair;
use function stream_wrapper_register;
use function strlen;
use function substr;
use function var_export;
use function wipe;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

use Bootgly\ABI\Resources\Storage\Drivers\S3\Reader;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Writer;
use Bootgly\ABI\Resources\Storage\tests\fixtures\S3 as Fake;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\S3;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Timeout;


require_once __DIR__ . '/s3.php';


// ! A source that serves `$size` bytes, then has nothing to read without being at its end
if (class_exists(Stutter::class, false) === false) {
   final class Stutter
   {
      public static int $size = 0;

      public mixed $context;
      private int $served = 0;

      public function stream_open (string $path, string $mode, int $options, null|string &$opened_path): bool
      {
         return true;
      }

      public function stream_read (int $count): string
      {
         $bytes = max(0, min($count, self::$size - $this->served));
         $this->served += $bytes;

         return str_repeat('t', $bytes);
      }

      public function stream_eof (): bool
      {
         return false;
      }

      /** @return array<string,mixed> */
      public function stream_stat (): array
      {
         return [];
      }
   }
}


/**
 * A write that cannot finish cleanly still ends bounded and leaves nothing behind it cannot see:
 * a stream with nothing to read yet is a read error, never a truncated object; the abort after a
 * deferral timeout waits a few seconds at most, and an upload it could not abort waits for the
 * next claim, which aborts a few of them at most and keeps the rest; a completion slower than
 * the request timeout is waited for, and a completion whose answer was lost is judged by the
 * store's state.
 */
return new Test(
   description: 'Resources: an S3 write never stores a truncated stream, bounds its abort and its orphan drain, and judges a lost completion by the store',
   skip: fake_skip(),
   test: function () {
      $base = Temporaries::reserve('wpi-s3-recovery');
      $Fake = Fake::start($base);
      $Rig = mount();
      $MiB = 1024 * 1024;

      try {
         $Writer = new Writer('', $Fake->configure());
         $S3 = new S3($Writer, part: 5 * $MiB, budget: 10 * $MiB, concurrency: 2, timeout: 10, connectTimeout: 2);
         $S3->schedule($Rig->park);
         // ! The uploads of this disk the worker waits to abort: `[key, upload id]`
         $Orphans = new ReflectionProperty(S3::class, 'Orphans');
         $queued = static function () use ($Orphans, $Writer): array {
            /** @var array<int,array{0:Writer,1:string,2:string,3:int}> $All */
            $All = $Orphans->getValue();

            return array_values(array_map(
               static fn (array $Orphan): array => [$Orphan[1], $Orphan[2]],
               array_filter($All, static fn (array $Orphan): bool => $Orphan[0] === $Writer)
            ));
         };
         $queue = static function (array $entries) use ($Orphans, $Writer): void {
            /** @var array<int,array{0:Writer,1:string,2:string,3:int}> $All */
            $All = $Orphans->getValue();
            foreach ($entries as [$key, $id]) {
               $All[] = [$Writer, $key, $id, 0];
            }
            $Orphans->setValue(null, array_values($All));
         };
         $aborts = static fn (): int => array_count_values(array_column($Fake->log(), 'operation'))['AbortMultipartUpload'] ?? 0;
         // ! The faults scripted and not consumed yet: none may leak into the next case
         $faults = static fn (): mixed => json_decode((string) file_get_contents("{$base}/faults.json"), true);
         $claim = static function () use ($Writer, $Rig): mixed {
            $Next = new S3($Writer, part: 5 * 1024 * 1024, budget: 10 * 1024 * 1024, timeout: 10, connectTimeout: 2);
            $Next->schedule($Rig->park);
            $Job = launch(static fn (): bool => $Next->check('nothing.txt'));
            spin($Rig, [$Job], 10.0);

            return $Job->result;
         };

         // # A stream with nothing to read yet (non-blocking, still open) is a read error
         [$Left, $Right] = (array) stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         fwrite($Left, 'abc');
         stream_set_blocking($Right, false);
         $Job = launch(static fn (): array => [$S3->write('partial.txt', $Right), $S3->error]);
         spin($Rig, [$Job], 5.0);
         $Operations = array_column($Fake->log(), 'operation');
         if (is_resource($Left)) {
            fclose($Left);
         }
         if (is_resource($Right)) {
            fclose($Right);
         }
         yield assert(
            assertion: $Job->result === [false, 'S3 write /test-bucket/partial.txt: source stream read error']
               && $Fake->fetch('partial.txt') === null
               && array_search('PutObject', $Operations, true) === false,
            description: 'An empty read of a stream not at its end fails the write and uploads nothing, found: ' . var_export([$Job->result, $Fake->fetch('partial.txt')], true)
         );

         // # The abort after a deferral timeout waits a few seconds at most, then the upload waits for the next claim
         $Fake->delay('UploadPart', 3.0);
         $Fake->delay('AbortMultipartUpload', 8.0);
         // ! Deadlines above the abort's: the abort runs under its own, then they come back
         $Patient = new S3($Writer, part: 5 * $MiB, budget: 10 * $MiB, concurrency: 2, timeout: 10, connectTimeout: 10);
         $Patient->schedule($Rig->park);
         $Used = (new ReflectionProperty(S3::class, 'Client'))->getValue($Patient);
         $Job = launch(static fn (): bool => $Patient->write('big/abort.bin', str_repeat('a', 10 * $MiB - 1)));
         $thrown = 0.0;
         $during = [];
         spin($Rig, [$Job], 12.0, static function (float $elapsed) use ($Job, $Used, &$thrown, &$during): void {
            if ($thrown === 0.0 && $elapsed > 0.3 && $Job->Fiber->isSuspended() === true) {
               $thrown = microtime(true);
               $Job->Fiber->throw(new Timeout(0.3));
            }
            if ($thrown > 0.0 && $during === [] && $Job->Fiber->isSuspended() === true) {
               $during = [$Used->timeout, $Used->connectTimeout];
            }
         });
         $waited = round(microtime(true) - $thrown, 3);
         $after = [$Used->timeout, $Used->connectTimeout];
         $left = $queued();
         $before = $aborts();
         $result = $claim();
         yield assert(
            assertion: $Job->result instanceof Timeout
               && $waited < 4.5
               && $during === [3, 3]
               && $after === [10, 10]
               && $Fake->fetch('big/abort.bin') === null
               && $left !== []
               && $left[0][0] === '/test-bucket/big/abort.bin'
               && $result === false
               && $aborts() === $before + 1
               && $queued() === [],
            description: "The abort waited {$waited} s under its own deadlines, then the next claim aborted the upload it left, found: " . var_export([$Job->result instanceof Timeout, $during, $after, $left, $result, $queued()], true)
         );

         // # A claim aborts two uploads at most; one whose abort fails goes back, up to three tries;
         //   one the store refuses for good is forgotten at once
         $queue([['/test-bucket/orphan/1.bin', 'gone-1'], ['/test-bucket/orphan/2.bin', 'gone-2'], ['/test-bucket/orphan/3.bin', 'gone-3']]);
         $before = $aborts();
         $claim();
         $drained = [$aborts() - $before, $queued()];
         $Fake->inject('AbortMultipartUpload', 500);
         $before = $aborts();
         $claim();
         $failed = [$aborts() - $before, $queued()];
         $claim();
         $Fake->inject('AbortMultipartUpload', 403, 'AccessDenied', 'Access Denied');
         $queue([['/test-bucket/orphan/6.bin', 'denied-6']]);
         $before = $aborts();
         $claim();
         $denied = [$aborts() - $before, $queued()];
         $Fake->inject('AbortMultipartUpload', 403, 'RequestTimeTooSkewed', 'The difference between the request time and the current time is too large.');
         $queue([['/test-bucket/orphan/8.bin', 'skewed-8']]);
         $before = $aborts();
         $claim();
         $skewed = [$aborts() - $before, $queued()];
         $claim();
         $Fake->inject('AbortMultipartUpload', 503, 'SlowDown', 'Please reduce your request rate.', times: 3);
         $queue([['/test-bucket/orphan/7.bin', 'busy-7']]);
         $before = $aborts();
         $tries = [];
         for ($claims = 0; $claims < 4; $claims++) {
            $claim();
            $tries[] = [$aborts() - $before, $queued()];
         }
         yield assert(
            assertion: $drained === [2, [['/test-bucket/orphan/3.bin', 'gone-3']]]
               && $failed === [1, [['/test-bucket/orphan/3.bin', 'gone-3']]]
               && $denied === [1, []]
               && $skewed === [1, [['/test-bucket/orphan/8.bin', 'skewed-8']]]
               && $tries === [
                  [1, [['/test-bucket/orphan/7.bin', 'busy-7']]],
                  [2, [['/test-bucket/orphan/7.bin', 'busy-7']]],
                  [3, []],
                  [3, []],
               ]
               && $queued() === [],
            description: 'One claim aborts two uploads and leaves the third; a failed abort keeps it for the next claim, three tries at most; an abort refused for good forgets it, a refusal that may pass keeps it, found: ' . var_export([$drained, $failed, $denied, $skewed, $tries, $queued()], true)
         );

         // # A claim cut while it aborts keeps every upload it took
         $queue([['/test-bucket/orphan/4.bin', 'gone-4'], ['/test-bucket/orphan/5.bin', 'gone-5']]);
         $Fake->delay('AbortMultipartUpload', 2.0);
         $Next = new S3($Writer, part: 5 * $MiB, budget: 10 * $MiB, timeout: 10, connectTimeout: 2);
         $Next->schedule($Rig->park);
         $Job = launch(static fn (): bool => $Next->check('nothing.txt'));
         $cut = false;
         spin($Rig, [$Job], 5.0, static function (float $elapsed) use ($Job, &$cut): void {
            if ($cut === false && $elapsed > 0.3 && $Job->Fiber->isSuspended() === true) {
               $cut = true;
               $Job->Fiber->throw(new Timeout(0.3));
            }
         });
         $kept = $queued();
         $claim();
         yield assert(
            assertion: $Job->result instanceof Timeout
               && $kept === [['/test-bucket/orphan/4.bin', 'gone-4'], ['/test-bucket/orphan/5.bin', 'gone-5']]
               && $queued() === [],
            description: 'A Timeout thrown into the orphan drain puts back the upload it was aborting and the ones it had not reached, found: ' . var_export([$Job->result instanceof Timeout, $kept, $queued()], true)
         );

         // # A completion slower than the request timeout is waited for
         $Quick = new S3($Writer, part: 5 * $MiB, budget: 10 * $MiB, concurrency: 2, timeout: 1, connectTimeout: 2);
         $Quick->schedule($Rig->park);
         $bytes = str_repeat('s', 10 * $MiB - 1);
         $Fake->delay('CompleteMultipartUpload', 1.5);
         $skip = count($Fake->log());
         $Job = launch(static fn (): array => [$Quick->write('big/slow.bin', $bytes), $Quick->error]);
         spin($Rig, [$Job], 10.0);
         $after = array_column(array_slice($Fake->log(), $skip), 'operation');
         yield assert(
            assertion: $Job->result === [true, '']
               && $Fake->fetch('big/slow.bin') === $bytes
               && array_search('HeadObject', $after, true) === false
               && array_search('ListParts', $after, true) === false
               && array_search('AbortMultipartUpload', $after, true) === false,
            description: 'A completion answered after the request timeout is still the store\'s answer, found: ' . var_export([$Job->result, $after], true)
         );

         // # A completion handled but whose answer is lost: the object the parts make is the store's word
         $Client = new ReflectionProperty(S3::class, 'Client');
         $Stream = fopen('php://memory', 'w+');
         $streamed = str_repeat('m', 12 * $MiB - 1);
         if ($Stream === false) {
            throw new RuntimeException('The memory stream did not open.');
         }
         fwrite($Stream, $streamed);
         rewind($Stream);
         $Fake->inject('CompleteMultipartUpload', -1);
         $skip = count($Fake->log());
         $Job = launch(static fn (): array => [$S3->write('lost/done.bin', $Stream), $S3->error]);
         spin($Rig, [$Job], 20.0);
         $after = array_column(array_slice($Fake->log(), $skip), 'operation');
         fclose($Stream);
         yield assert(
            assertion: $Job->result === [true, '']
               && $Fake->fetch('lost/done.bin') === $streamed
               && array_search('HeadObject', $after, true) !== false
               && array_search('AbortMultipartUpload', $after, true) === false
               && $faults() === [],
            description: 'A completion handled but unanswered is a success when the object at the key is the one the parts make — a replay answered NoSuchUpload included, found: ' . var_export([$Job->result, $after], true)
         );

         // # A completion handled, its answer lost, and the object not shown (HEAD fails): unknown, never true
         $Fake->inject('CompleteMultipartUpload', -1);
         $Fake->inject('HeadObject', 500);
         $Job = launch(static fn (): array => [$S3->write('lost/unknown.bin', str_repeat('u', 10 * $MiB - 1)), $S3->error]);
         spin($Rig, [$Job], 20.0);
         $written = $Job->result;
         $Fake->inject('CompleteMultipartUpload', -1);
         $Fake->inject('HeadObject', 500);
         $Job = launch(static function () use ($S3): array {
            $id = $S3->Uploads->create('lost/handle.bin');
            $etag = $id !== false ? $S3->Uploads->put('lost/handle.bin', $id, 1, 'handle') : false;

            return [$id !== false && $etag !== false && $S3->Uploads->complete('lost/handle.bin', $id, [1 => ['etag' => $etag]]), $S3->error];
         });
         spin($Rig, [$Job], 20.0);
         yield assert(
            assertion: is_array($written)
               && $written[0] === false
               && str_contains((string) $written[1], "the completion's outcome is unknown")
               && $Fake->fetch('lost/unknown.bin') !== null
               && is_array($Job->result)
               && $Job->result[0] === false
               && str_contains((string) $Job->result[1], "the completion's outcome is unknown")
               && $Fake->fetch('lost/handle.bin') === 'handle'
               && $faults() === [],
            description: 'A lost completion that cannot be judged fails, by write() and by Uploads->complete(), found: ' . var_export([$written, $Job->result], true)
         );

         // # A completion never handled: the upload still listed is aborted, the write fails (two
         //   drops: the client replays a request its reused connection dropped unanswered)
         $Fake->inject('CompleteMultipartUpload', 0, times: 2);
         $skip = count($Fake->log());
         $Job = launch(static fn (): array => [$S3->write('lost/open.bin', str_repeat('o', 10 * $MiB - 1)), $S3->error]);
         spin($Rig, [$Job], 20.0);
         $after = array_column(array_slice($Fake->log(), $skip), 'operation');
         yield assert(
            assertion: $Job->result === [false, 'S3 complete multipart /test-bucket/lost/open.bin: HTTP 0']
               && $Fake->fetch('lost/open.bin') === null
               && $Fake->list() === []
               && array_search('ListParts', $after, true) !== false
               && ($after[count($after) - 1] ?? '') === 'AbortMultipartUpload',
            description: 'A completion never handled leaves the upload listed: the write fails and aborts it, found: ' . var_export([$Job->result, $after], true)
         );
         file_put_contents("{$base}/faults.json", '[]');

         // # The same bytes written again with other headers, the completion never handled: the
         //   object at the key matches the parts, but the upload still listed says it was not completed
         $same = str_repeat('r', 10 * $MiB - 1);
         $Job = launch(static fn (): bool => $S3->write('lost/same.bin', $same, ['type' => 'text/old']));
         spin($Rig, [$Job], 20.0);
         $first = $Job->result;
         $Fake->inject('CompleteMultipartUpload', 0, times: 2);
         $skip = count($Fake->log());
         $Job = launch(static fn (): array => [$S3->write('lost/same.bin', $same, ['type' => 'text/new']), $S3->error]);
         spin($Rig, [$Job], 20.0);
         $after = array_column(array_slice($Fake->log(), $skip), 'operation');
         file_put_contents("{$base}/faults.json", '[]');
         yield assert(
            assertion: $first === true
               && $Job->result === [false, 'S3 complete multipart /test-bucket/lost/same.bin: HTTP 0']
               && ($Fake->describe('lost/same.bin')['type'] ?? null) === 'text/old'
               && $Fake->list() === []
               && ($after[count($after) - 1] ?? '') === 'AbortMultipartUpload',
            description: 'A rewrite of the same bytes whose completion never ran fails and aborts its upload, never a success over the older object, found: ' . var_export([$first, $Job->result, $after], true)
         );

         // # The upload gone (aborted by someone else) and the object of the same bytes at the key:
         //   a success only when its headers are the written ones
         $Initiate = new ReflectionMethod(S3::class, 'initiate');
         $Pump = new ReflectionMethod(S3::class, 'pump');
         $Terminate = new ReflectionMethod(S3::class, 'terminate');
         $Conclude = new ReflectionMethod(S3::class, 'conclude');
         $Job = launch(static function () use ($S3, $Writer, $Fake, $Initiate, $Pump, $Terminate, $Conclude, $same, $MiB): array {
            $key = '/test-bucket/lost/same.bin';
            $judged = [];
            foreach (['text/new', 'text/old'] as $type) {
               $extra = ['Content-Type' => $type];
               $id = $Initiate->invoke($S3, $key, $extra);
               $ETags = [];
               $Pump->invokeArgs($S3, [$key, $id, [1 => substr($same, 0, 5 * $MiB), 2 => substr($same, 5 * $MiB)], &$ETags]);
               $completion = $Writer->join($ETags);
               $Terminate->invoke($S3, [$key, $id]);
               $Fake->inject('CompleteMultipartUpload', 0, times: 2);
               $judged[$type] = $Conclude->invoke($S3, $key, $id, $completion, $ETags, strlen($same), $extra);
            }

            return $judged;
         });
         spin($Rig, [$Job], 20.0);
         $headed = $Job->result;
         file_put_contents("{$base}/faults.json", '[]');
         // ! The store refuses to list the parts and to show the object (write-only credentials);
         //   two drops, so the client's replay is lost too and the upload's state is asked
         $Fake->inject('CompleteMultipartUpload', -1, times: 2);
         $Fake->inject('ListParts', 403, 'AccessDenied', 'Access Denied');
         $Fake->inject('HeadObject', 403, 'AccessDenied', 'Access Denied');
         $skip = count($Fake->log());
         $Job = launch(static fn (): array => [$S3->write('lost/blind.bin', $same), $S3->error]);
         spin($Rig, [$Job], 20.0);
         $after = array_column(array_slice($Fake->log(), $skip), 'operation');
         file_put_contents("{$base}/faults.json", '[]');
         yield assert(
            assertion: $headed === ['text/new' => null, 'text/old' => true]
               && ($Fake->describe('lost/same.bin')['type'] ?? null) === 'text/old'
               && is_array($Job->result)
               && $Job->result[0] === false
               && str_contains((string) $Job->result[1], "the completion's outcome is unknown")
               && $Fake->fetch('lost/blind.bin') === $same
               && (array_search('ListParts', $after, true) === false || ($after[count($after) - 1] ?? '') === 'AbortMultipartUpload')
               && $Fake->list() === [],
            description: 'An object whose headers are not the written ones, or a store that shows neither the upload nor the object, is an unknown outcome; an upload it will not show is aborted (harmless to the completed object), one the store says is gone is not, found: ' . var_export([$headed, $Job->result, $after], true)
         );

         // # The upload gone and an older object of the same size at the key: never a success
         $scripted = "{$base}/faults.json";
         $Job = launch(static function () use ($S3, $Writer, $Fake, $Initiate, $Pump, $Terminate, $Conclude, $scripted): array {
            $key = '/test-bucket/lost/old.bin';
            $id = $Initiate->invoke($S3, $key, []);
            $ETags = [];
            $Pump->invokeArgs($S3, [$key, $id, [1 => 'NEW'], &$ETags]);
            $completion = $Writer->join($ETags);
            // ! Someone else aborts the upload; an older object of the same size sits at the key
            $Terminate->invoke($S3, [$key, $id]);
            $Fake->seed('lost/old.bin', 'OLD');
            $Fake->inject('CompleteMultipartUpload', 0, times: 2);
            $old = [$Conclude->invoke($S3, $key, $id, $completion, $ETags, 3), $S3->error];

            // ! An object whose ETag is the parts' fold: a success only at the written size
            $sized = [];
            foreach ([3, 4] as $length) {
               $Fake->inject('CompleteMultipartUpload', 0, times: 2);
               $Fake->inject('HeadObject', 200, raw: "HTTP/1.1 200 OK\r\nETag: " . Reader::fold($ETags) . "\r\nContent-Length: {$length}\r\nConnection: close\r\n\r\n");
               $sized[$length] = $Conclude->invoke($S3, $key, $id, $completion, $ETags, 3);
            }
            file_put_contents($scripted, '[]');

            return [...$old, $sized];
         });
         spin($Rig, [$Job], 20.0);
         yield assert(
            assertion: is_array($Job->result)
               && $Job->result[0] === null
               && str_contains((string) $Job->result[1], "the completion's outcome is unknown")
               && $Job->result[2] === [3 => true, 4 => null]
               && $Fake->fetch('lost/old.bin') === 'OLD',
            description: 'An object of the same size that the parts do not make, or of the parts\' ETag at another size, is an unknown outcome, never a success, found: ' . var_export($Job->result, true)
         );

         // # Failure paths: a failed part whose abort fails leaves the upload to the next claim, and
         //   the client's deadlines are the configured ones afterwards
         $Fake->inject('UploadPart', 500);
         $Fake->inject('AbortMultipartUpload', 500, times: 3);
         $before = $aborts();
         $Job = launch(static fn (): array => [$S3->write('fail/part.bin', str_repeat('p', 10 * $MiB - 1)), $S3->error]);
         spin($Rig, [$Job], 20.0);
         $left = $queued();
         $Used = $Client->getValue($S3);
         $deadlines = [$Used->timeout, $Used->connectTimeout];
         $claim();
         $claim();
         $claim();
         // ! Three aborts in all — the write's own counts — then the upload is forgotten
         $tried = [$aborts() - $before, $queued(), count($Fake->list())];
         $queue($left);
         $claim();
         yield assert(
            assertion: is_array($Job->result)
               && $Job->result[0] === false
               && str_contains((string) $Job->result[1], 'S3 upload part 1: HTTP 500')
               && count($left) === 1
               && $left[0][0] === '/test-bucket/fail/part.bin'
               && $deadlines === [10, 2]
               && $tried === [3, [], 1]
               && $queued() === []
               && $Fake->list() === [],
            description: 'A failed part whose abort fails queues the upload for the next claim, three aborts in all, and the deadlines come back, found: ' . var_export([$Job->result, $left, $deadlines, $tried, $queued()], true)
         );

         // # A read error after the upload opened aborts it
         if (in_array('stutter', stream_get_wrappers(), true) === false) {
            stream_wrapper_register('stutter', Stutter::class);
         }
         Stutter::$size = 5 * $MiB + 1;
         $Source = fopen('stutter://source', 'r');
         $skip = count($Fake->log());
         $Job = launch(static fn (): array => [$S3->write('fail/stutter.bin', $Source), $S3->error]);
         spin($Rig, [$Job], 10.0);
         $after = array_column(array_slice($Fake->log(), $skip), 'operation');
         yield assert(
            assertion: $Job->result === [false, 'S3 multipart: source stream read error']
               && $after === ['CreateMultipartUpload', 'UploadPart', 'AbortMultipartUpload']
               && $Fake->list() === []
               && $Fake->fetch('fail/stutter.bin') === null,
            description: 'An empty read before the end, once the upload is open, fails the write and aborts it, found: ' . var_export([$Job->result, $after], true)
         );
      }
      finally {
         ($Rig->restore)();
         $Fake->stop();
         wipe($base);
      }
   }
);
