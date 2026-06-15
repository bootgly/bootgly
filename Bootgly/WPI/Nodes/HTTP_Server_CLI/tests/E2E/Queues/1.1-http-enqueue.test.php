<?php

use Bootgly\ACI\Queues as QueueManager;
use Bootgly\ACI\Queues\Job;
use Bootgly\ACI\Queues\tests\Recorder;
use Bootgly\WPI\Queues;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   request: function () {
      return "GET /email/zoe@example.com HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      // ! Runs inside the real HTTP server worker — the HTTP→queue integration point.
      //   Enqueue (fast, non-blocking) and reply immediately.
      $Job = Queues::dispatch(Recorder::class, ['to' => 'zoe@example.com'], 'e2e');

      return $Response->JSON->send(['queued' => true, 'job' => $Job->id]);
   },
   test: function ($response) {
      // ? 1) The HTTP response proves the handler ran and dispatched
      if (str_contains($response, '"queued":true') === false) {
         return 'HTTP response did not confirm the job was queued';
      }

      // ? 2) The job really landed in the shared file store (written by the server worker)
      $Queue = new QueueManager(['driver' => 'file'])->fetch('e2e');
      $Job = $Queue->reserve();
      if ($Job instanceof Job === false) {
         return 'no job was enqueued by the HTTP request';
      }
      if ($Job->Handler !== Recorder::class || $Job->payload !== ['to' => 'zoe@example.com']) {
         return 'the enqueued job did not carry the expected handler and payload';
      }

      // @ Cleanup the claimed job
      $Queue->complete($Job);

      return true;
   }
);
