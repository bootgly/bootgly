<?php

use Bootgly\ACI\Queues\Job;
use Bootgly\ACI\Queues\tests\Recorder;
use Bootgly\WPI\Queues;
use Bootgly\WPI\Queues\Messenger;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Messenger and the WPI Queues facade enqueue jobs into the ACI queue',
   test: function () {
      $path = sys_get_temp_dir() . '/bootgly-qmsg-' . uniqid('', true);

      // # dispatch() builds a Job from handler + payload and enqueues it
      $Messenger = new Messenger(['path' => $path]);
      $Job = $Messenger->dispatch(Recorder::class, ['to' => 'x'], 'mail');

      $Reserved = $Messenger->Queues->fetch('mail')->reserve();
      yield assert(
         assertion: $Reserved instanceof Job
            && $Reserved->id === $Job->id
            && $Reserved->Handler === Recorder::class
            && $Reserved->payload === ['to' => 'x'],
         description: 'dispatch() enqueues a job carrying the handler and payload'
      );

      // # push() enqueues a prepared Job
      $Prepared = new Job(Recorder::class, ['n' => 1]);
      $Messenger->push($Prepared, 'mail');

      $Next = $Messenger->Queues->fetch('mail')->reserve();
      yield assert(
         assertion: $Next instanceof Job && $Next->id === $Prepared->id,
         description: 'push() enqueues the given job'
      );

      // # static facade dispatches through a shared messenger
      $other = sys_get_temp_dir() . '/bootgly-qmsg-' . uniqid('', true);
      $Facade = Queues::boot(['path' => $other]);
      $Dispatched = Queues::dispatch(Recorder::class, ['a' => 1]);

      $Seen = $Facade->Queues->fetch('default')->reserve();
      yield assert(
         assertion: $Seen instanceof Job && $Seen->id === $Dispatched->id,
         description: 'the static facade dispatches through the shared Messenger'
      );
   }
);
