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
use function assert;
use function count;
use function fake_skip;
use function fclose;
use function fopen;
use function fwrite;
use function json_encode;
use function microtime;
use function rewind;
use function round;
use function str_repeat;
use function var_export;
use function wipe;
use RuntimeException;

use Bootgly\ABI\Resources\Storage\Drivers\S3\Failure\Kinds;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Writer;
use Bootgly\ABI\Resources\Storage\tests\fixtures\S3 as Fake;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\S3;


require_once __DIR__ . '/s3.php';


/**
 * The parts of one write travel together, a large write never takes the last part of a budget of
 * two parts or more, and the worker's in-flight budget is one counter for every S3 resource of the
 * worker: a write past it is refused by name while others hold it, and accepted once it is back.
 */
return new Test(
   description: 'Resources: the S3 resource sends the parts of a write together and refuses a write past the worker in-flight budget by name',
   skip: fake_skip(),
   test: function () {
      $base = Temporaries::reserve('wpi-s3-overlap');
      $Fake = Fake::start($base);
      $Rig = mount();
      $MiB = 1024 * 1024;
      // ! Two parts: 5 MiB and 5 MiB - 1
      $bytes = str_repeat('o', 10 * $MiB - 1);

      $budget = 15 * $MiB;

      try {
         $Writer = new Writer('', $Fake->configure());
         $S3 = new S3($Writer, part: 5 * $MiB, budget: $budget, concurrency: 4, timeout: 10, connectTimeout: 2);
         $S3->schedule($Rig->park);
         $Second = new S3($Writer, part: 5 * $MiB, budget: $budget, concurrency: 4, timeout: 10, connectTimeout: 2);
         $Second->schedule($Rig->park);
         $Other = new S3($Writer, part: 5 * $MiB, budget: $budget, concurrency: 4, timeout: 10, connectTimeout: 2);
         $Other->schedule($Rig->park);

         // # Overlap: two parts each held 1 s by the store end in less than 2 s
         $hold = 1.0;
         $Fake->delay('UploadPart', $hold, times: 2);
         $start = microtime(true);
         $Job = launch(static fn (): bool => $S3->write('big/overlap.bin', $bytes));
         spin($Rig, [$Job], 10.0);
         $elapsed = microtime(true) - $start;
         yield assert(
            assertion: $Job->result === true
               && $Fake->fetch('big/overlap.bin') === $bytes
               && $elapsed < 2 * $hold,
            description: 'The two parts of a write are in flight together: held ' . $hold . ' s each, the write took ' . round($elapsed, 3) . ' s, found: ' . var_export($Job->result, true)
         );

         // # Budget: a large write never takes the budget's last part — a second large write is
         //   refused by name, a small one of another resource still goes, a small stream too (its
         //   size shows only as it is read), and a large stream is refused before it sends a byte
         $Tiny = fopen('php://memory', 'w+');
         $Large = fopen('php://memory', 'w+');
         if ($Tiny === false || $Large === false) {
            throw new RuntimeException('The memory streams did not open.');
         }
         fwrite($Tiny, 'tiny');
         rewind($Tiny);
         fwrite($Large, str_repeat('l', 6 * $MiB));
         rewind($Large);
         $Fake->delay('UploadPart', $hold, times: 2);
         $Holder = launch(static fn (): bool => $S3->write('big/holder.bin', $bytes));
         spin($Rig, [$Holder], 0.2);
         $held = measure();
         $During = launch(static fn (): array => [
            $Second->write('big/refused.bin', $bytes),
            $Second->error,
            $Second->Failure?->kind,
            $Other->write('small.txt', 'x'),
            $Other->error,
            $Other->write('small-stream.txt', $Tiny),
            $Other->error,
            $Second->write('big/stream.bin', $Large),
            $Second->error,
         ]);
         spin($Rig, [$Holder, $During], 10.0);
         fclose($Tiny);
         fclose($Large);
         $Accepted = launch(static fn (): bool => $Other->write('big/accepted.bin', str_repeat('a', $budget)));
         spin($Rig, [$Accepted], 10.0);
         $Operations = array_count_values(array_column($Fake->log(), 'operation'));
         yield assert(
            assertion: $held === 10 * $MiB
               && $During->result === [
                  false,
                  "S3 write /test-bucket/big/refused.bin: the worker's in-flight budget ({$budget} bytes) cannot take one more part",
                  Kinds::Refusal,
                  true,
                  '',
                  true,
                  '',
                  false,
                  "S3 write /test-bucket/big/stream.bin: the worker's in-flight budget ({$budget} bytes) cannot take one more part",
               ]
               && ($Operations['CreateMultipartUpload'] ?? 0) === 3
               && $Fake->fetch('small-stream.txt') === 'tiny'
               && $Fake->fetch('big/stream.bin') === null
               && $Holder->result === true
               && $Fake->fetch('big/holder.bin') === $bytes
               && $Fake->fetch('small.txt') === 'x'
               && $Fake->fetch('big/refused.bin') === null
               && $Accepted->result === true
               && measure() === 0,
            description: 'A large write leaves the last part of the budget: another large write (a string or a stream) is refused by name and sends nothing, a small write (a string or a stream) goes, and a write of exactly the budget goes once it is back, found: ' . var_export([$held, $During->result, $Accepted->result], true) . json_encode($Operations)
         );

         // # A budget of one part: one write takes it all, and every other write is refused meanwhile
         $Lone = new S3($Writer, part: 5 * $MiB, budget: 5 * $MiB, concurrency: 4, timeout: 10, connectTimeout: 2);
         $Lone->schedule($Rig->park);
         $Peer = new S3($Writer, part: 5 * $MiB, budget: 5 * $MiB, concurrency: 4, timeout: 10, connectTimeout: 2);
         $Peer->schedule($Rig->park);
         $Fake->delay('UploadPart', $hold);
         $Holder = launch(static fn (): bool => $Lone->write('big/lone.bin', $bytes));
         spin($Rig, [$Holder], 0.2);
         $held = measure();
         $During = launch(static fn (): array => [$Peer->write('tiny.txt', 'y'), $Peer->error]);
         spin($Rig, [$Holder, $During], 10.0);
         yield assert(
            assertion: $held === 5 * $MiB
               && $During->result === [false, "S3 write /test-bucket/tiny.txt: the worker's in-flight budget (" . (5 * $MiB) . ' bytes) cannot take 1 more bytes']
               && $Holder->result === true
               && measure() === 0,
            description: 'Under a budget of one part one write holds the whole budget, found: ' . var_export([$held, $During->result, $Holder->result], true)
         );

         // # A write waiting for its completion has nothing in flight but the completion body:
         //   its part slots are back, and another large write goes meanwhile
         $Fake->delay('CompleteMultipartUpload', 3.0);
         $Joining = launch(static fn (): bool => $S3->write('big/joining.bin', $bytes));
         $joining = null;
         $Meanwhile = null;
         spin($Rig, [$Joining], 10.0, static function (float $elapsed) use ($Fake, $Joining, $Second, $bytes, &$joining, &$Meanwhile): void {
            $Operations = array_column($Fake->log(), 'operation');
            if (
               $Meanwhile === null
               && $Joining->Fiber?->isSuspended() === true
               && ($Operations[count($Operations) - 1] ?? '') === 'CompleteMultipartUpload'
            ) {
               $joining = measure();
               $Meanwhile = launch(static fn (): array => [$Second->write('big/meanwhile.bin', $bytes), $Second->error]);
            }
         });
         if ($Meanwhile !== null) {
            spin($Rig, [$Meanwhile], 10.0);
         }
         yield assert(
            assertion: $joining !== null
               && $joining > 0
               && $joining < $MiB
               && $Meanwhile?->result === [true, '']
               && $Joining->result === true
               && $Fake->fetch('big/joining.bin') === $bytes
               && $Fake->fetch('big/meanwhile.bin') === $bytes
               && measure() === 0,
            description: 'A write waiting for its completion holds only its body, and a large write goes meanwhile, found: ' . var_export([$joining, $Meanwhile?->result, $Joining->result], true)
         );
      }
      finally {
         ($Rig->restore)();
         $Fake->stop();
         wipe($base);
      }
   }
);
