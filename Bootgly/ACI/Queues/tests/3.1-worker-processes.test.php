<?php

use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Queues;
use Bootgly\ACI\Queues\Job;
use Bootgly\ACI\Queues\Events;
use Bootgly\ACI\Queues\Worker;
use Bootgly\ACI\Queues\tests\Recorder;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/Recorder.php';


return new Specification(
   description: 'Worker::tick runs the handler, completes the job and emits Processed',
   test: function () {
      Recorder::$handled = [];

      $now = 1_000_000_000;
      $clock = function () use (&$now) { return $now; };
      $path = sys_get_temp_dir() . '/bootgly-queue-' . uniqid('', true);

      $Queues = new Queues(['path' => $path, 'clock' => $clock]);
      $Queue = $Queues->fetch('default');
      $Worker = new Worker($Queue, $Queues->Config);

      $processed = null;
      Emitter::$Instance->listen(Events::Processed, function (Emission $E) use (&$processed) { $processed = $E->payload; });

      $Queue->enqueue(new Job(Recorder::class, ['n' => 7]));

      yield assert(
         assertion: $Worker->tick() === true,
         description: 'tick handled one job'
      );
      yield assert(
         assertion: Recorder::$handled === [['n' => 7]],
         description: 'the handler ran with the job payload'
      );
      yield assert(
         assertion: $Queue->count() === 0,
         description: 'the job was completed (queue empty)'
      );
      yield assert(
         assertion: $processed !== null && $processed[0] instanceof Job,
         description: 'Processed carries the Job'
      );
      yield assert(
         assertion: $processed !== null && is_float($processed[1]),
         description: 'Processed carries the duration in milliseconds'
      );

      yield assert(
         assertion: $Worker->tick() === false,
         description: 'tick is idle once the queue drains'
      );
   }
);
