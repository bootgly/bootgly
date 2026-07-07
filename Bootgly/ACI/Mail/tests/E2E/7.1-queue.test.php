<?php

use Bootgly\ACI\Mail\Message;
use Bootgly\ACI\Mail\SMTP_Client\Encoder;
use Bootgly\ACI\Queues\Job;
use Bootgly\ACI\Queues\Worker;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Queues;
use Bootgly\WPI\Services\Mail;
use Bootgly\WPI\Services\Mail\Courier;


return new Specification(
   description: 'E2E: queued mail — dispatch on the request side, Courier delivers in the worker',
   test: function () {
      // @ An unbooted Courier refuses to run (worker bootstrap contract)
      $caught = false;
      try {
         new Courier()->handle(new Job(Courier::class, []));
      }
      catch (RuntimeException) {
         $caught = true;
      }
      yield assert(
         assertion: $caught,
         description: 'the Courier throws before WPI\Services\Mail::boot() wires the shared mailer'
      );

      // # Request side — boot the platform queue store + the mail messenger
      $trace = [];
      $path = sys_get_temp_dir() . '/bootgly-mail-queue-' . uniqid('', true);
      Queues::boot(['driver' => 'file', 'path' => $path]);
      Mail::boot([
         'host' => '127.0.0.1',
         'port' => 9998,
         'secure' => 'none',
         'domain' => 'happy',
         'timeout' => 5.0,
         'wait' => 5.0,
         'trace' => function (string $direction, string $line) use (&$trace): void {
            $trace[] = "{$direction} {$line}";
         }
      ]);

      // ! Deterministic message — the worker re-render must match ours
      $Message = new Message();
      $Message->from = 'Bootgly <no-reply@bootgly.com>';
      $Message->to = 'user@example.net';
      $Message->bcc = 'auditor@example.net';
      $Message->subject = 'Fila E2E';
      $Message->text = 'Queued plain body.';
      $Message->html = '<p>Queued <strong>HTML</strong> body.</p>';
      $Message->id = 'queue-e2e@bootgly.com';
      $Message->date = 'Mon, 06 Jul 2026 12:00:00 -0300';
      $Message->boundary = 'queueseed';

      $Job = Mail::dispatch($Message);
      $Queue = Queues::$Messenger->Queues->fetch('mail');

      yield assert(
         assertion: $Job->Handler === Courier::class && $Queue->count() === 1,
         description: 'dispatch() enqueues one Courier job on the shared WPI\Queues messenger'
      );

      // # Worker side — exactly what `bootgly queue run mail` runs
      $Worker = new Worker($Queue, Queues::$Messenger->Queues->Config);

      yield assert(
         assertion: $Worker->tick() === true,
         description: 'the worker reserves and processes the queued mail job'
      );
      yield assert(
         assertion: $Queue->count() === 0,
         description: 'the queue is drained after delivery'
      );

      // @ Wire proof: the mock replied the sha1 of the received DATA bytes —
      //   it must match the local render + stuffing of the SAME message
      $expected = sha1(new Encoder()->stuff($Message->render()));
      $confirmed = false;
      foreach ($trace as $line) {
         if (str_contains($line, "sha1={$expected}")) {
            $confirmed = true;
         }
      }
      yield assert(
         assertion: $confirmed,
         description: 'the delivered wire bytes match render()+stuff() of the dispatched message'
      );

      // @ The envelope crossed the queue intact (bcc included, To header only)
      $rcpts = [];
      foreach ($trace as $line) {
         if (str_starts_with($line, '> RCPT TO:<')) {
            $rcpts[] = substr($line, 11, -1);
         }
      }
      yield assert(
         assertion: $rcpts === ['user@example.net', 'auditor@example.net'],
         description: 'the worker sent RCPT for to + bcc derived from the imported message'
      );

      // @ Facade sync path against the same mock
      $Direct = new Message();
      $Direct->from = 'no-reply@bootgly.com';
      $Direct->to = 'user@example.net';
      $Direct->subject = 'Sync';
      $Direct->text = 'Immediate delivery.';

      $Receipt = Mail::send($Direct);
      yield assert(
         assertion: $Receipt->code === 250 && $Receipt->recipients === ['user@example.net'],
         description: 'Mail::send() delivers synchronously through the shared messenger'
      );

      Mail::$Messenger->Mail->disconnect();
   }
);
