<?php

use Bootgly\ACI\Queues;
use Bootgly\ACI\Queues\Job;
use Bootgly\ACI\Queues\tests\Recorder;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/Recorder.php';


return new Specification(
   description: 'bury removes a job from circulation (dead-letter)',
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

      $Queue->bury($Reserved);
      yield assert(
         assertion: $Queue->count() === 0,
         description: 'a buried job is not ready'
      );
      yield assert(
         assertion: $Queue->reserve() === null,
         description: 'a buried job is not reservable'
      );
   }
);
