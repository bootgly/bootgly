<?php

use Bootgly\ACI\Queues;
use Bootgly\ACI\Queues\Job;
use Bootgly\ACI\Queues\tests\Recorder;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/Recorder.php';


return new Specification(
   description: 'enqueue → count → reserve → complete drains the queue',
   test: function () {
      $now = 1_000_000_000;
      $clock = function () use (&$now) { return $now; };
      $path = sys_get_temp_dir() . '/bootgly-queue-' . uniqid('', true);

      $Queue = new Queues(['path' => $path, 'clock' => $clock])->fetch('default');

      $Queue->enqueue(new Job(Recorder::class, ['n' => 1]));

      yield assert(
         assertion: $Queue->count() === 1,
         description: 'one job ready after enqueue'
      );

      $Reserved = $Queue->reserve();
      yield assert(
         assertion: $Reserved instanceof Job,
         description: 'reserve returns the job'
      );
      yield assert(
         assertion: $Queue->count() === 0,
         description: 'a reserved job no longer counts as ready'
      );

      $Queue->complete($Reserved);
      yield assert(
         assertion: $Queue->reserve() === null,
         description: 'queue is empty after complete'
      );
   }
);
