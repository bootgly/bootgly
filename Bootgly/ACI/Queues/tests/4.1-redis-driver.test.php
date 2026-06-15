<?php

use Bootgly\ACI\Queues;
use Bootgly\ACI\Queues\Backoffs;
use Bootgly\ACI\Queues\Job;
use Bootgly\ACI\Queues\tests\Recorder;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/Recorder.php';


// ! Probe a reachable Redis server (native socket — no ext-redis required)
$host = getenv('REDIS_HOST') !== false ? (string) getenv('REDIS_HOST') : '127.0.0.1';
$port = getenv('REDIS_PORT') !== false ? (int) getenv('REDIS_PORT') : 6379;
$Probe = @fsockopen($host, $port, $errno, $error, 0.2);
$reachable = is_resource($Probe);
if ($reachable === true) {
   fclose($Probe);
}


return new Specification(
   description: 'Queue(Redis): blocking driver contract over RESP (requires a reachable Redis)',
   skip: $reachable === false,
   test: function () use ($host, $port) {
      $now = 1_000_000_000;
      $clock = function () use (&$now) { return $now; };
      $config = [
         'driver' => 'redis',
         'host' => $host, 'port' => $port,
         'prefix' => 'bootgly-qtest-' . uniqid() . ':',
         'clock' => $clock,
         'attempts' => 3, 'base' => 10, 'visibility' => 60,
         'backoff' => Backoffs::Fixed,
      ];

      $Queue = new Queues($config)->fetch('default');
      $Queue->clear();

      // # enqueue → reserve → complete
      $Queue->enqueue(new Job(Recorder::class, ['n' => 1]));
      yield assert(
         assertion: $Queue->count() === 1,
         description: 'one job ready after enqueue'
      );
      $Reserved = $Queue->reserve();
      yield assert(
         assertion: $Reserved instanceof Job && $Reserved->payload === ['n' => 1],
         description: 'reserve returns the job with its payload intact'
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

      // # delayed availability
      $Delayed = new Job(Recorder::class);
      $Delayed->postpone($now + 100);
      $Queue->enqueue($Delayed);
      yield assert(
         assertion: $Queue->reserve() === null,
         description: 'a future job is not yet due'
      );
      $now += 100;
      yield assert(
         assertion: $Queue->reserve() instanceof Job,
         description: 'the job is due once its timestamp arrives'
      );

      // # release → retry with backoff
      $Queue->clear();
      $Queue->enqueue(new Job(Recorder::class));
      $Retry = $Queue->reserve();
      $Queue->release($Retry, 50);
      yield assert(
         assertion: $Retry instanceof Job && $Retry->attempts === 1,
         description: 'the attempt is recorded on release'
      );
      yield assert(
         assertion: $Queue->reserve() === null,
         description: 'the released job is not due during its backoff'
      );
      $now += 50;
      $Again = $Queue->reserve();
      yield assert(
         assertion: $Again instanceof Job && $Again->attempts === 1,
         description: 'the job is due again with one recorded attempt'
      );

      // # bury (dead-letter)
      $Queue->clear();
      $Queue->enqueue(new Job(Recorder::class));
      $Queue->bury($Queue->reserve());
      yield assert(
         assertion: $Queue->count() === 0 && $Queue->reserve() === null,
         description: 'a buried job is neither ready nor reservable'
      );

      // # recover stale claims (visibility deadline elapsed)
      $Queue->clear();
      $Queue->enqueue(new Job(Recorder::class));
      $Stale = $Queue->reserve();
      yield assert(
         assertion: $Stale instanceof Job && $Queue->reserve() === null,
         description: 'nothing else is ready while the job is reserved'
      );
      $now += 61;
      yield assert(
         assertion: $Queue->recover() === 1,
         description: 'the reaper recovers the stale claim'
      );
      yield assert(
         assertion: $Queue->reserve() instanceof Job,
         description: 'the recovered job is reservable again'
      );

      // # two workers never claim the same job (atomic ZREM)
      $Queue->clear();
      $Worker1 = new Queues($config)->fetch('default');
      $Worker2 = new Queues($config)->fetch('default');
      $Worker1->enqueue(new Job(Recorder::class, ['k' => 1]));
      $now += 1;
      $Worker1->enqueue(new Job(Recorder::class, ['k' => 2]));
      $A = $Worker1->reserve();
      $B = $Worker2->reserve();
      yield assert(
         assertion: $A instanceof Job && $B instanceof Job && $A->id !== $B->id,
         description: 'no job is claimed by both workers'
      );
      yield assert(
         assertion: $Worker1->reserve() === null,
         description: 'the queue is drained — no third job'
      );

      // ! Leave Redis clean
      $Queue->clear();
   }
);
