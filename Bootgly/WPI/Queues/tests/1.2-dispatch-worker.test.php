<?php

use Bootgly\ACI\Queues\Job;
use Bootgly\ACI\Queues\Worker;
use Bootgly\ACI\Queues\tests\Recorder;
use Bootgly\WPI\Queues;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/../../../ACI/Queues/tests/Recorder.php';


return new Specification(
   description: 'the HTTP loop end to end: facade dispatch (request side) → worker processes the handler',
   test: function () {
      Recorder::$handled = [];

      $path = sys_get_temp_dir() . '/bootgly-qloop-' . uniqid('', true);

      // # Request side — exactly what an HTTP route handler runs
      $Messenger = Queues::boot(['driver' => 'file', 'path' => $path]);
      $Job = Queues::dispatch(Recorder::class, ['to' => 'alice@example.com'], 'emails');

      yield assert(
         assertion: $Job instanceof Job && $Messenger->Queues->fetch('emails')->count() === 1,
         description: 'dispatch from the request side enqueues one job'
      );

      // # Worker side — exactly what `bootgly queue run emails` runs
      $Queue = $Messenger->Queues->fetch('emails');
      $Worker = new Worker($Queue, $Messenger->Queues->Config);

      yield assert(
         assertion: $Worker->tick() === true,
         description: 'the worker reserves and processes the dispatched job'
      );
      yield assert(
         assertion: Recorder::$handled === [['to' => 'alice@example.com']],
         description: 'the handler ran with the payload sent from the request'
      );
      yield assert(
         assertion: $Queue->count() === 0,
         description: 'the queue is drained after processing'
      );
   }
);
