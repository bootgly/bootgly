<?php

use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Queues;
use Bootgly\ACI\Queues\Backoffs;
use Bootgly\ACI\Queues\Job;
use Bootgly\ACI\Queues\Events;
use Bootgly\ACI\Queues\Worker;
use Bootgly\ACI\Queues\tests\Thrower;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/Thrower.php';


return new Specification(
   description: 'a job is buried once attempts are exhausted, emitting Failed(willRetry: false)',
   test: function () {
      $now = 1_000_000_000;
      $clock = function () use (&$now) { return $now; };
      $path = sys_get_temp_dir() . '/bootgly-queue-' . uniqid('', true);

      $Queues = new Queues([
         'path' => $path, 'clock' => $clock,
         'attempts' => 2, 'base' => 10, 'backoff' => Backoffs::Fixed,
      ]);
      $Queue = $Queues->fetch('default');
      $Worker = new Worker($Queue, $Queues->Config);

      $failed = null;
      Emitter::$Instance->listen(Events::Failed, function (Emission $E) use (&$failed) { $failed = $E->payload; });

      $Queue->enqueue(new Job(Thrower::class));

      // @ Attempt 1 → failure → release with backoff
      $Worker->tick();
      $now += 10;
      // @ Attempt 2 → failure → buried (attempts == max)
      $Worker->tick();

      yield assert(
         assertion: $failed !== null && $failed[2] === false,
         description: 'the final Failed signals no further retry'
      );
      yield assert(
         assertion: $Queue->count() === 0,
         description: 'no ready jobs remain'
      );
      yield assert(
         assertion: $Queue->reserve() === null,
         description: 'the buried job is not reservable'
      );
   }
);
