<?php

use Bootgly\ACI\Queues;
use Bootgly\ACI\Queues\Job;
use Bootgly\ACI\Queues\tests\Recorder;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/Recorder.php';


return new Specification(
   description: 'a reserved job round-trips its handler, payload and id through storage',
   test: function () {
      $now = 1_000_000_000;
      $clock = function () use (&$now) { return $now; };
      $path = sys_get_temp_dir() . '/bootgly-queue-' . uniqid('', true);

      $Queue = new Queues(['path' => $path, 'clock' => $clock])->fetch('default');

      $Job = new Job(Recorder::class, ['user' => 42, 'msg' => 'hi']);
      $Queue->enqueue($Job);

      $Reserved = $Queue->reserve();

      yield assert(
         assertion: $Reserved instanceof Job,
         description: 'the job is reserved'
      );
      yield assert(
         assertion: $Reserved instanceof Job && $Reserved->Handler === Recorder::class,
         description: 'the handler class-string is preserved'
      );
      yield assert(
         assertion: $Reserved instanceof Job && $Reserved->payload === ['user' => 42, 'msg' => 'hi'],
         description: 'the payload is preserved'
      );
      yield assert(
         assertion: $Reserved instanceof Job && $Reserved->id === $Job->id,
         description: 'the id is preserved'
      );
   }
);
