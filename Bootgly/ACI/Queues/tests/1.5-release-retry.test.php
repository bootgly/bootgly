<?php

use Bootgly\ACI\Queues;
use Bootgly\ACI\Queues\Job;
use Bootgly\ACI\Queues\tests\Recorder;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/Recorder.php';


return new Specification(
   description: 'release requeues a job after a backoff delay and counts the attempt',
   test: function () {
      $now = 1_000_000_000;
      $clock = function () use (&$now) { return $now; };
      $path = sys_get_temp_dir() . '/bootgly-queue-' . uniqid('', true);

      $Queue = new Queues(['path' => $path, 'clock' => $clock])->fetch('default');

      $Queue->enqueue(new Job(Recorder::class));

      $Reserved = $Queue->reserve();
      yield assert(
         assertion: $Reserved instanceof Job,
         description: 'the job is reserved'
      );

      $Queue->release($Reserved, 50);
      yield assert(
         assertion: $Reserved instanceof Job && $Reserved->attempts === 1,
         description: 'the attempt is recorded on release'
      );
      yield assert(
         assertion: $Queue->reserve() === null,
         description: 'the released job is not due during its backoff'
      );

      $now += 50;
      $Again = $Queue->reserve();
      yield assert(
         assertion: $Again instanceof Job && $Again->id === $Reserved->id,
         description: 'the job is due again after the backoff elapses'
      );
      yield assert(
         assertion: $Again instanceof Job && $Again->attempts === 1,
         description: 'the attempt count persists across the requeue'
      );
   }
);
