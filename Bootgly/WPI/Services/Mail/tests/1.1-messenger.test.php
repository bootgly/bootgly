<?php

use Bootgly\ACI\Mail\Message;
use Bootgly\ACI\Queues\Job;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Queues;
use Bootgly\WPI\Services\Mail;
use Bootgly\WPI\Services\Mail\Courier;
use Bootgly\WPI\Services\Mail\Messenger;


return new Specification(
   description: 'WPI\Services\Mail + Messenger: dispatch enqueues a Courier job on the shared queue (no SMTP touched)',
   test: function () {
      $path = sys_get_temp_dir() . '/bootgly-mail-service-' . uniqid('', true);

      // # Request side — exactly what an HTTP route handler runs
      Queues::boot(['driver' => 'file', 'path' => $path]);   // the platform queue store
      $Messenger = Mail::boot(['host' => 'smtp.example.com', 'secure' => 'none']);

      yield assert(
         assertion: Mail::$Messenger === $Messenger && $Messenger instanceof Messenger,
         description: 'boot() builds and stores the shared messenger'
      );
      yield assert(
         assertion: Courier::$Mail === $Messenger->Mail,
         description: 'boot() wires the queue Courier to the same shared mailer'
      );

      $Message = new Message();
      $Message->from = 'no-reply@bootgly.com';
      $Message->to = 'user@example.net';
      $Message->subject = 'Queued';
      $Message->text = 'Deliver me later.';

      $Job = Mail::dispatch($Message);

      yield assert(
         assertion: $Job instanceof Job && $Job->Handler === Courier::class,
         description: 'dispatch() enqueues a Job handled by the mail Courier'
      );
      yield assert(
         assertion: $Job->payload['from'] === 'no-reply@bootgly.com'
            && $Job->payload['subject'] === 'Queued',
         description: 'the Job payload carries the exported message'
      );
      yield assert(
         assertion: Queues::$Messenger->Queues->fetch('mail')->count() === 1,
         description: 'the job lands on the `mail` queue of the shared WPI\Queues messenger'
      );

      // @ The persisted job crosses processes: Job is the only allowed class
      $stored = unserialize(serialize($Job), ['allowed_classes' => [Job::class]]);
      yield assert(
         assertion: $stored instanceof Job
            && $stored->payload === $Job->payload
            && Message::import($stored->payload)->render() !== '',
         description: 'the serialized job rebuilds an equivalent, renderable message'
      );

      // @ A custom queue name is honoured
      Mail::dispatch($Message, 'newsletters');
      yield assert(
         assertion: Queues::$Messenger->Queues->fetch('newsletters')->count() === 1,
         description: 'dispatch() targets the given queue name'
      );
   }
);
