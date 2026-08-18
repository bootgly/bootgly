<?php

use Bootgly\ACI\Queues;
use Bootgly\ACI\Queues\Job;
use Bootgly\ACI\Queues\tests\Recorder;
use Bootgly\ACI\Tests\Suite\Test;

require_once __DIR__ . '/Recorder.php';


return new Test(
   description: 'recover measures the visibility window from the claim, not from the ready file mtime',
   test: function () {
      // ! A non-zero visibility window — `1.7-recover-reaper` uses 0, which
      //   makes every claim stale by construction and hides this entirely.
      $path = sys_get_temp_dir() . '/bootgly-queue-' . uniqid('', true);
      $Queue = new Queues(['path' => $path, 'visibility' => 60])->fetch('default');

      $locate = function (string $sub) use ($path): null|string {
         foreach (@scandir("{$path}/default/{$sub}") ?: [] as $entry) {
            if (str_ends_with($entry, '.job') === true) {
               return "{$path}/default/{$sub}/{$entry}";
            }
         }

         return null;
      };

      $Job = new Job(Recorder::class);
      $Queue->enqueue($Job);

      $ready = $locate('ready');
      yield assert(
         assertion: $ready !== null,
         description: 'the job is written to ready/'
      );

      // ! Backdate the ready file past the window: exactly what a backlog, a
      //   delayed job, or a `release()` backoff wider than the window produces.
      //   `reserve()` claims with `rename()`, which preserves the mtime.
      touch($ready, time() - 300);

      $Reserved = $Queue->reserve();
      yield assert(
         assertion: $Reserved instanceof Job && $Reserved->id === $Job->id,
         description: 'the backlogged job is claimed'
      );

      yield assert(
         assertion: $Queue->recover() === 0,
         description: 'the reaper leaves a claim made inside the visibility window alone'
      );
      yield assert(
         assertion: $Queue->reserve() === null,
         description: 'the live claim is never handed to a second worker'
      );

      // # A claim that really did outlive the window is still recovered.
      $reserved = $locate('reserved');
      yield assert(
         assertion: $reserved !== null,
         description: 'the claim is still held in reserved/'
      );

      touch($reserved, time() - 300);
      yield assert(
         assertion: $Queue->recover() === 1,
         description: 'the reaper still recovers a claim older than the visibility window'
      );

      $Again = $Queue->reserve();
      yield assert(
         assertion: $Again instanceof Job && $Again->id === $Job->id,
         description: 'the recovered job is reservable again'
      );
      $Queue->complete($Again);

      // # The claim stamp is a `touch()`, which CREATES the file when it is
      //   missing — a reaper that re-readied the claim between the rename and
      //   the stamp would leave a 0-byte record behind. The stamp is placed
      //   before `load()` precisely so the corrupt-record path absorbs it; this
      //   pins that path, since losing it would turn the stamp into a leak.
      $Queue->enqueue(new Job(Recorder::class));
      file_put_contents("{$path}/default/ready/00000000001-phantom.job", '');

      $Claimed = $Queue->reserve();
      $held = [];
      foreach (@scandir("{$path}/default/reserved") ?: [] as $entry) {
         if (str_ends_with($entry, '.job') === true) {
            $held[] = $entry;
         }
      }

      yield assert(
         assertion: $Claimed instanceof Job,
         description: 'an empty job record does not stop the claim scan'
      );
      yield assert(
         assertion: in_array('00000000001-phantom.job', $held, true) === false,
         description: 'an empty job record is never left behind in reserved/'
      );

      // ! Drop the scratch store.
      $Queue->clear();
      foreach (['ready', 'reserved', 'failed'] as $sub) {
         @rmdir("{$path}/default/{$sub}");
      }
      @rmdir("{$path}/default");
      @rmdir($path);
   }
);
