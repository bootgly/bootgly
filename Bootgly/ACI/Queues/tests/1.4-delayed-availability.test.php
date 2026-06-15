<?php

use Bootgly\ACI\Queues;
use Bootgly\ACI\Queues\Job;
use Bootgly\ACI\Queues\tests\Recorder;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/Recorder.php';


return new Specification(
   description: 'a job postponed to the future is not reservable until it becomes due',
   test: function () {
      $now = 1_000_000_000;
      $clock = function () use (&$now) { return $now; };
      $path = sys_get_temp_dir() . '/bootgly-queue-' . uniqid('', true);

      $Queue = new Queues(['path' => $path, 'clock' => $clock])->fetch('default');

      $Job = new Job(Recorder::class);
      $Job->postpone($now + 100);
      $Queue->enqueue($Job);

      yield assert(
         assertion: $Queue->reserve() === null,
         description: 'a future job is not yet due'
      );

      $now += 100;
      yield assert(
         assertion: $Queue->reserve() instanceof Job,
         description: 'the job is due once its timestamp arrives'
      );
   }
);
