<?php

use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Queues;
use Bootgly\ACI\Queues\Backoffs;
use Bootgly\ACI\Queues\Job;
use Bootgly\ACI\Queues\Events;
use Bootgly\ACI\Queues\Worker;
use Bootgly\ACI\Queues\tests\Thrower;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/Thrower.php';


return new Specification(
   description: 'a failing handler is requeued with backoff and emits Failed(willRetry: true)',
   test: function () {
      $now = 1_000_000_000;
      $clock = function () use (&$now) { return $now; };
      $path = sys_get_temp_dir() . '/bootgly-queue-' . uniqid('', true);

      $Queues = new Queues([
         'path' => $path, 'clock' => $clock,
         'attempts' => 3, 'base' => 10, 'backoff' => Backoffs::Fixed,
      ]);
      $Queue = $Queues->fetch('default');
      $Worker = new Worker($Queue, $Queues->Config);

      $failed = null;
      Emitter::$Instance->listen(Events::Failed, function (Emission $E) use (&$failed) { $failed = $E->payload; });

      $Queue->enqueue(new Job(Thrower::class));

      yield assert(
         assertion: $Worker->tick() === true,
         description: 'tick attempted the failing job'
      );
      yield assert(
         assertion: $failed !== null && $failed[2] === true,
         description: 'Failed signals willRetry on a retryable failure'
      );
      yield assert(
         assertion: $Queue->reserve() === null,
         description: 'the job is not due during its backoff window'
      );

      $now += 10;
      $Again = $Queue->reserve();
      yield assert(
         assertion: $Again instanceof Job && $Again->attempts === 1,
         description: 'the job is due again with one recorded attempt'
      );
   }
);
