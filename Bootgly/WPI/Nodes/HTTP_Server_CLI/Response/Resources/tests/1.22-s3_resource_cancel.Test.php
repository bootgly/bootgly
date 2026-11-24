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


use function array_column;
use function array_count_values;
use function array_slice;
use function assert;
use function count;
use function fake_skip;
use function fopen;
use function fwrite;
use function gc_collect_cycles;
use function in_array;
use function memory_get_usage;
use function microtime;
use function rewind;
use function str_contains;
use function str_repeat;
use function var_export;
use function wipe;
use Closure;
use ReflectionProperty;

use Bootgly\ABI\Resources\Storage\Drivers\S3\Writer;
use Bootgly\ABI\Resources\Storage\tests\fixtures\S3 as Fake;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\S3;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Timeout;


require_once __DIR__ . '/s3.php';


/**
 * A write that ends before its store answered leaves no open upload and no budget behind: a
 * deferral `Timeout` thrown into it aborts the upload before the throw leaves it, and a request
 * destroyed while it waits leaves its upload to the next claim of a resource of the same disk.
 * After the throw, `$Failure` says what it left of the object: null only when the store shows
 * the object stored (a cut completion the store took), else nothing stored or its outcome unknown.
 */
return new Test(
   description: 'Resources: an S3 write cut by a deferral timeout or destroyed while it waits leaves no open upload and no budget behind',
   skip: fake_skip(),
   test: function () {
      $base = Temporaries::reserve('wpi-s3-cancel');
      $Fake = Fake::start($base);
      $Rig = mount();
      $MiB = 1024 * 1024;
      $bytes = str_repeat('c', 10 * $MiB - 1);

      try {
         $Writer = new Writer('', $Fake->configure());
         $S3 = new S3($Writer, part: 5 * $MiB, budget: 10 * $MiB, concurrency: 2, timeout: 10, connectTimeout: 2);
         $S3->schedule($Rig->park);

         // # A deferral timeout thrown into a write whose parts are held by the store
         $Fake->delay('UploadPart', 3.0);
         $Job = launch(static fn (): bool => $S3->write('big/timeout.bin', $bytes));
         $thrown = false;
         spin($Rig, [$Job], 8.0, static function (float $elapsed) use ($Job, &$thrown): void {
            if ($thrown === false && $elapsed > 0.3 && $Job->Fiber->isSuspended() === true) {
               $thrown = true;
               $Job->Fiber->throw(new Timeout(0.3));
            }
         });
         $Operations = array_column($Fake->log(), 'operation');
         yield assert(
            assertion: $thrown === true
               && $Job->result instanceof Timeout
               && $Fake->list() === []
               && ($Operations[count($Operations) - 1] ?? '') === 'AbortMultipartUpload'
               && $Fake->fetch('big/timeout.bin') === null
               && measure() === 0
               && str_contains($S3->error, 'nothing stored'),
            description: 'The Timeout leaves the write after its upload was aborted and its budget given back, and says nothing was stored, found: ' . var_export([$Job->result instanceof Timeout, $Fake->list(), measure(), $S3->error], true)
         );

         // # The whole budget is usable again
         $Job = launch(static fn (): bool => $S3->write('big/after.bin', str_repeat('a', 10 * $MiB)));
         spin($Rig, [$Job], 10.0);
         yield assert(
            assertion: $Job->result === true && $Fake->list() === [] && measure() === 0,
            description: 'After a timeout a write of exactly the budget goes, found: ' . var_export($Job->result, true)
         );

         // ! Cut the write once `$operation` reached the store; `$Job->cut` is when
         $cut = static function (object $Job, string $operation = 'CompleteMultipartUpload') use ($Fake): Closure {
            $Job->cut = 0.0;

            return static function (float $elapsed) use ($Job, $Fake, $operation): void {
               $Operations = array_column($Fake->log(), 'operation');
               if (
                  $Job->cut === 0.0
                  && $Job->Fiber->isSuspended() === true
                  && ($Operations[count($Operations) - 1] ?? '') === $operation
               ) {
                  $Job->cut = microtime(true);
                  $Job->Fiber->throw(new Timeout(0.3));
               }
            };
         };

         // # Cut during a completion the store took: the object is stored, and `$Failure` stays null
         $Fake->delay('CompleteMultipartUpload', 3.0);
         $Job = launch(static fn (): bool => $S3->write('big/joined.bin', $bytes, ['type' => 'text/joined']));
         spin($Rig, [$Job], 8.0, $cut($Job));
         $Operations = array_column($Fake->log(), 'operation');
         yield assert(
            assertion: $Job->result instanceof Timeout
               && $S3->Failure === null
               && $S3->error === ''
               && $Fake->fetch('big/joined.bin') === $bytes
               && $Fake->list() === []
               && ($Operations[count($Operations) - 1] ?? '') === 'HeadObject'
               && measure() === 0,
            description: 'A completion the store took is judged stored: no failure after the Timeout, found: ' . var_export([$Job->result instanceof Timeout, $S3->error, $Fake->fetch('big/joined.bin') === $bytes, $Fake->list(), array_slice($Operations, -3)], true)
         );

         // # Cut during a completion the store refused: the upload is aborted and nothing stored
         $Fake->delay('CompleteMultipartUpload', 3.0);
         $Fake->inject('CompleteMultipartUpload', 500);
         $Job = launch(static fn (): bool => $S3->write('big/refused.bin', $bytes));
         spin($Rig, [$Job], 8.0, $cut($Job));
         yield assert(
            assertion: $Job->result instanceof Timeout
               && $S3->Failure !== null
               && str_contains($S3->error, 'nothing stored')
               && $Fake->fetch('big/refused.bin') === null
               && $Fake->list() === []
               && measure() === 0,
            description: 'A completion the store did not take is judged not stored after the Timeout, found: ' . var_export([$Job->result instanceof Timeout, $S3->error, $Fake->list()], true)
         );

         // # Cut during a completion the store took, but the store will not show the object: unknown
         $Fake->delay('CompleteMultipartUpload', 3.0);
         $Fake->inject('HeadObject', 403, 'AccessDenied', 'Access Denied');
         $Job = launch(static fn (): bool => $S3->write('big/blind.bin', $bytes));
         spin($Rig, [$Job], 8.0, $cut($Job));
         yield assert(
            assertion: $Job->result instanceof Timeout
               && str_contains($S3->error, 'outcome is unknown')
               && $Fake->fetch('big/blind.bin') === $bytes
               && $Fake->list() === []
               && measure() === 0,
            description: 'A completion the store took but will not show is judged unknown after the Timeout, found: ' . var_export([$Job->result instanceof Timeout, $S3->error, $Fake->list()], true)
         );

         // # Cut while the context's first call aborts an upload left open: nothing was stored
         $Orphans = new ReflectionProperty(S3::class, 'Orphans');
         $saved = $Orphans->getValue();
         $Orphans->setValue(null, [[$Writer, $Writer->locate('orphan/left.bin'), 'left-1', 0]]);
         $Fake->delay('AbortMultipartUpload', 3.0);
         $Job = launch(static fn (): bool => $S3->write('small/swept.txt', 'hi'));
         spin($Rig, [$Job], 8.0, $cut($Job, 'AbortMultipartUpload'));
         $Operations = array_column($Fake->log(), 'operation');
         $Orphans->setValue(null, $saved);
         yield assert(
            assertion: $Job->result instanceof Timeout
               && $S3->Failure !== null
               && str_contains($S3->error, 'small/swept.txt')
               && str_contains($S3->error, 'nothing stored')
               && $Fake->fetch('small/swept.txt') === null
               && in_array('PutObject', $Operations, true) === false
               && measure() === 0,
            description: 'A write cut while it aborts an upload left open says nothing was stored, found: ' . var_export([$Job->result instanceof Timeout, $S3->error, array_slice($Operations, -3)], true)
         );

         // # Cut during a single PUT: the store may have the object — its outcome is unknown
         foreach (['string' => 'hi', 'stream' => 'stream'] as $kind => $content) {
            $Fake->delay('PutObject', 3.0);
            $source = $content;
            if ($kind === 'stream') {
               $source = fopen('php://memory', 'w+');
               fwrite($source, $content);
               rewind($source);
            }
            $Job = launch(static fn (): bool => $S3->write("small/{$kind}.txt", $source));
            spin($Rig, [$Job], 8.0, $cut($Job, 'PutObject'));
            yield assert(
               assertion: $Job->result instanceof Timeout
                  && str_contains($S3->error, 'outcome is unknown')
                  && $Fake->fetch("small/{$kind}.txt") === $content
                  && measure() === 0,
               description: "A single PUT ({$kind}) cut in flight says its outcome is unknown, found: " . var_export([$Job->result instanceof Timeout, $S3->error], true)
            );
         }

         // # Cut during the abort after a completion whose answer was lost: judged by the store
         //   — stored, so the unknown outcome the completion recorded is settled
         $Fake->inject('CompleteMultipartUpload', -1, times: 2);
         $Fake->inject('ListParts', 500);
         $Fake->inject('HeadObject', 500);
         $Fake->delay('AbortMultipartUpload', 3.0);
         $Job = launch(static fn (): bool => $S3->write('big/lost.bin', $bytes));
         spin($Rig, [$Job], 10.0, $cut($Job, 'AbortMultipartUpload'));
         $Operations = array_column($Fake->log(), 'operation');
         yield assert(
            assertion: $Job->result instanceof Timeout
               && $S3->Failure === null
               && $S3->error === ''
               && $Fake->fetch('big/lost.bin') === $bytes
               && $Fake->list() === []
               && ($Operations[count($Operations) - 1] ?? '') === 'HeadObject'
               && measure() === 0,
            description: 'A cut abort after a lost completion is judged by the store: stored, found: ' . var_export([$Job->result instanceof Timeout, $S3->error, $Fake->fetch('big/lost.bin') === $bytes, array_slice($Operations, -4)], true)
         );

         // # The store's check after a cut is bounded like the abort, and the client's bounds
         //   come back
         $Fake->delay('CompleteMultipartUpload', 3.0);
         $Fake->delay('HeadObject', 30.0);
         $Job = launch(static fn (): bool => $S3->write('big/stall.bin', $bytes));
         spin($Rig, [$Job], 15.0, $cut($Job));
         $ended = microtime(true);
         $Client = (new ReflectionProperty(S3::class, 'Client'))->getValue($S3);
         yield assert(
            assertion: $Job->result instanceof Timeout
               && $Job->cut > 0.0
               && $ended - $Job->cut < 7.0
               && str_contains($S3->error, 'outcome is unknown')
               && [$Client->timeout, $Client->connectTimeout] === [10, 2]
               && measure() === 0,
            description: 'The check after a cut ends within the abort bounds and gives the client its bounds back, found: ' . var_export([$Job->result instanceof Timeout, $ended - $Job->cut, $S3->error, [$Client->timeout, $Client->connectTimeout]], true)
         );

         // # Cut during a completion the store refused, then aborted, while the store will not
         //   show the key: unknown — "nothing stored" needs the store to show no such object
         $Fake->delay('CompleteMultipartUpload', 3.0);
         $Fake->inject('CompleteMultipartUpload', 500);
         $Fake->inject('HeadObject', 403, 'AccessDenied', 'Access Denied');
         $Job = launch(static fn (): bool => $S3->write('big/hidden.bin', $bytes));
         spin($Rig, [$Job], 8.0, $cut($Job));
         yield assert(
            assertion: $Job->result instanceof Timeout
               && str_contains($S3->error, 'outcome is unknown')
               && $Fake->fetch('big/hidden.bin') === null
               && $Fake->list() === []
               && measure() === 0,
            description: 'An aborted completion the store will not show is judged unknown, found: ' . var_export([$Job->result instanceof Timeout, $S3->error, $Fake->list()], true)
         );

         // # Cut during the completion, then cut again during its abort: still unknown
         $Fake->delay('CompleteMultipartUpload', 3.0);
         $Fake->delay('AbortMultipartUpload', 3.0);
         $Job = launch(static fn (): bool => $S3->write('big/twice.bin', $bytes));
         $first = $cut($Job);
         $second = false;
         spin($Rig, [$Job], 10.0, static function (float $elapsed) use ($Job, $Fake, $first, &$second): void {
            $first($elapsed);
            $Operations = array_column($Fake->log(), 'operation');
            if (
               $second === false
               && $Job->cut > 0.0
               && $Job->Fiber?->isSuspended() === true
               && ($Operations[count($Operations) - 1] ?? '') === 'AbortMultipartUpload'
            ) {
               $second = true;
               $Job->Fiber->throw(new Timeout(0.3));
            }
         });
         $Orphans->setValue(null, $saved);
         yield assert(
            assertion: $Job->result instanceof Timeout
               && $second === true
               && str_contains($S3->error, 'outcome is unknown')
               && measure() === 0,
            description: 'A write cut twice — in its completion, then in its abort — says its outcome is unknown, found: ' . var_export([$Job->result instanceof Timeout, $second, $S3->error], true)
         );

         // # Cut while parts are in flight: the budget and the part bodies are given back before
         //   the abort waits
         gc_collect_cycles();
         $memory = memory_get_usage();
         $Fake->delay('UploadPart', 3.0);
         $Fake->delay('AbortMultipartUpload', 3.0);
         $Job = launch(static fn (): bool => $S3->write('big/parts.bin', $bytes));
         $first = $cut($Job, 'UploadPart');
         $during = null;
         spin($Rig, [$Job], 10.0, static function (float $elapsed) use ($Job, $Fake, $first, $memory, &$during): void {
            $first($elapsed);
            $Operations = array_column($Fake->log(), 'operation');
            if (
               $during === null
               && $Job->cut > 0.0
               && $Job->Fiber?->isSuspended() === true
               && ($Operations[count($Operations) - 1] ?? '') === 'AbortMultipartUpload'
            ) {
               $during = [measure(), memory_get_usage() - $memory];
            }
         });
         yield assert(
            assertion: $Job->result instanceof Timeout
               && $during !== null
               && $during[0] === 0
               && $during[1] < 2 * $MiB
               && str_contains($S3->error, 'nothing stored'),
            description: 'While a cut write waits for its abort, it holds no budget and no part bodies, found: ' . var_export([$Job->result instanceof Timeout, $during, $S3->error], true)
         );

         // # The context's first call aborts the uploads left open, whatever the call
         foreach (['read' => static fn (): mixed => $S3->read('nothing.txt'), 'delete' => static fn (): mixed => $S3->delete('nothing.txt')] as $call => $Work) {
            $aborts = array_count_values(array_column($Fake->log(), 'operation'))['AbortMultipartUpload'] ?? 0;
            $Orphans->setValue(null, [[$Writer, $Writer->locate("orphan/{$call}.bin"), "left-{$call}", 0]]);
            $Job = launch($Work);
            spin($Rig, [$Job], 5.0);
            $left = $Orphans->getValue();
            $Orphans->setValue(null, $saved);
            yield assert(
               assertion: $left === []
                  && (array_count_values(array_column($Fake->log(), 'operation'))['AbortMultipartUpload'] ?? 0) === $aborts + 1,
               description: "A context whose first call is {$call}() aborts the upload left open, found: " . var_export($left, true)
            );
         }

         // # A request destroyed while its parts wait: its generation settles, its Fiber is collected
         $Fake->delay('UploadPart', 3.0);
         $Job = launch(static fn (): bool => $S3->write('big/evicted.bin', $bytes));
         spin($Rig, [$Job], 0.3);
         $parked = $Job->Fiber->isSuspended();
         // ! The generation settles first, while its Fiber still exists: its budget is back then
         $Job->Token->cancel();
         $budget = measure();
         $Job->Fiber = null;
         gc_collect_cycles();
         $left = $Fake->list();

         // # The next claim of a resource of this disk aborts the upload it left
         $Next = new S3($Writer, part: 5 * $MiB, budget: 10 * $MiB, concurrency: 2, timeout: 10, connectTimeout: 2);
         $Next->schedule($Rig->park);
         $Claim = launch(static fn (): bool => $Next->check('big/evicted.bin'));
         spin($Rig, [$Claim], 10.0);
         yield assert(
            assertion: $parked === true
               && count($left) === 1
               && ($left[0]['key'] ?? '') === 'big/evicted.bin'
               && $budget === 0
               && $Claim->result === false
               && $Fake->list() === [],
            description: 'A destroyed write gives its budget back at once and leaves its upload to the next claim, which aborts it, found: ' . var_export([$parked, $left, $budget, $Claim->result, $Fake->list()], true)
         );
      }
      finally {
         ($Rig->restore)();
         $Fake->stop();
         wipe($base);
      }
   }
);
