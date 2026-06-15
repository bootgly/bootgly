<?php

use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Queues;
use Bootgly\ACI\Queues\Events;
use Bootgly\ACI\Queues\Job;
use Bootgly\ACI\Queues\tests\Recorder;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/Recorder.php';


return new Specification(
   description: 'enqueue emits Dispatch carrying the queue name and the Job',
   test: function () {
      $Emitter = Emitter::$Instance;

      $captured = null;
      $Emitter->listen(Events::Dispatch, function (Emission $E) use (&$captured) { $captured = $E->payload; });

      $now = 1_000_000_000;
      $clock = function () use (&$now) { return $now; };
      $path = sys_get_temp_dir() . '/bootgly-queue-' . uniqid('', true);

      $Queue = new Queues(['path' => $path, 'clock' => $clock])->fetch('mail');
      $Queue->enqueue(new Job(Recorder::class, ['to' => 'x']));

      yield assert(
         assertion: $captured !== null && $captured[0] === 'mail',
         description: 'Dispatch carries the queue name'
      );
      yield assert(
         assertion: $captured !== null && $captured[1] instanceof Job,
         description: 'Dispatch carries the Job'
      );
   }
);
