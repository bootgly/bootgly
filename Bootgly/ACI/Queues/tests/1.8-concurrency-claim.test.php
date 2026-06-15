<?php

use Bootgly\ACI\Queues;
use Bootgly\ACI\Queues\Job;
use Bootgly\ACI\Queues\tests\Recorder;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/Recorder.php';


return new Specification(
   description: 'two workers over the same storage never claim the same job (atomic rename)',
   test: function () {
      $now = 1_000_000_000;
      $clock = function () use (&$now) { return $now; };
      $path = sys_get_temp_dir() . '/bootgly-queue-' . uniqid('', true);

      // @ Two independent managers over the same path simulate two worker processes
      $Worker1 = new Queues(['path' => $path, 'clock' => $clock])->fetch('jobs');
      $Worker2 = new Queues(['path' => $path, 'clock' => $clock])->fetch('jobs');

      $Worker1->enqueue(new Job(Recorder::class, ['k' => 1]));
      $now += 1;
      $Worker1->enqueue(new Job(Recorder::class, ['k' => 2]));

      $A = $Worker1->reserve();
      $B = $Worker2->reserve();

      yield assert(
         assertion: $A instanceof Job && $B instanceof Job,
         description: 'both workers reserved a job'
      );
      yield assert(
         assertion: $A instanceof Job && $B instanceof Job && $A->id !== $B->id,
         description: 'no job was claimed by both workers'
      );
      yield assert(
         assertion: $Worker1->reserve() === null,
         description: 'the queue is drained — no third job exists'
      );
   }
);
