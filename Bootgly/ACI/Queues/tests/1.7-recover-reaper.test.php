<?php

use Bootgly\ACI\Queues;
use Bootgly\ACI\Queues\Job;
use Bootgly\ACI\Queues\tests\Recorder;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/Recorder.php';


return new Specification(
   description: 'recover returns claims left reserved past the visibility timeout',
   test: function () {
      $now = 1_000_000_000;
      $clock = function () use (&$now) { return $now; };
      $path = sys_get_temp_dir() . '/bootgly-queue-' . uniqid('', true);

      // ! visibility 0 → any reserved file is immediately stale (deterministic, no sleep)
      $Queue = new Queues(['path' => $path, 'clock' => $clock, 'visibility' => 0])->fetch('default');

      $Job = new Job(Recorder::class);
      $Queue->enqueue($Job);

      $Reserved = $Queue->reserve();
      yield assert(
         assertion: $Reserved instanceof Job,
         description: 'the job is reserved'
      );
      yield assert(
         assertion: $Queue->reserve() === null,
         description: 'nothing else is ready while the job is reserved'
      );

      yield assert(
         assertion: $Queue->recover() === 1,
         description: 'the reaper recovers the stale claim'
      );

      $Again = $Queue->reserve();
      yield assert(
         assertion: $Again instanceof Job && $Again->id === $Job->id,
         description: 'the recovered job is reservable again'
      );
   }
);
