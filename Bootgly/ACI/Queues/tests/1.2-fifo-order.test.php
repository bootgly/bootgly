<?php

use Bootgly\ACI\Queues;
use Bootgly\ACI\Queues\Job;
use Bootgly\ACI\Queues\tests\Recorder;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/Recorder.php';


return new Specification(
   description: 'reserve returns the earliest-due job first (FIFO by availability)',
   test: function () {
      $now = 1_000_000_000;
      $clock = function () use (&$now) { return $now; };
      $path = sys_get_temp_dir() . '/bootgly-queue-' . uniqid('', true);

      $Queue = new Queues(['path' => $path, 'clock' => $clock])->fetch('default');

      $A = new Job(Recorder::class, ['k' => 'a']);
      $Queue->enqueue($A);

      $now += 1;
      $B = new Job(Recorder::class, ['k' => 'b']);
      $Queue->enqueue($B);

      $First = $Queue->reserve();
      yield assert(
         assertion: $First instanceof Job && $First->id === $A->id,
         description: 'the first-enqueued job is reserved first'
      );

      $Second = $Queue->reserve();
      yield assert(
         assertion: $Second instanceof Job && $Second->id === $B->id,
         description: 'the second-enqueued job is reserved next'
      );
   }
);
