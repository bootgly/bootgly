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
use function assert;
use function count;
use function fake_skip;
use function gc_collect_cycles;
use function str_repeat;
use function var_export;
use function wipe;

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
               && measure() === 0,
            description: 'The Timeout leaves the write after its upload was aborted and its budget given back, found: ' . var_export([$Job->result instanceof Timeout, $Fake->list(), measure()], true)
         );

         // # The whole budget is usable again
         $Job = launch(static fn (): bool => $S3->write('big/after.bin', str_repeat('a', 10 * $MiB)));
         spin($Rig, [$Job], 10.0);
         yield assert(
            assertion: $Job->result === true && $Fake->list() === [] && measure() === 0,
            description: 'After a timeout a write of exactly the budget goes, found: ' . var_export($Job->result, true)
         );

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
