<?php

use Bootgly\ACI\Schedule;
use Bootgly\ACI\Schedule\Catchups;
use Bootgly\ACI\Schedule\Frequencies;
use Bootgly\ACI\Schedule\State;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Schedule::recover() runs Catchups::Once exactly once for missed runs; Catchups::Skip just advances the baseline',
   test: function () {
      @unlink(BOOTGLY_WORKING_DIR . '/workdata/schedule/state.json');

      // ! Seed a last-run in the past so a daily run is missed
      $Seed = new State();
      $past = mktime(3, 0, 0, 6, 1, 2026);
      $Seed->update('cu_once', $past);
      $Seed->update('cu_skip', $past);

      $ranOnce = 0;
      $ranSkip = 0;

      $Schedule = new Schedule();
      $Schedule->add('cu_once', function () use (&$ranOnce) { $ranOnce++; })
         ->repeat(Frequencies::Daily, at: '03:00')
         ->recover(Catchups::Once);
      $Schedule->add('cu_skip', function () use (&$ranSkip) { $ranSkip++; })
         ->repeat(Frequencies::Daily, at: '03:00')
         ->recover(Catchups::Skip);

      // @ Many days later, with runs missed
      $now = mktime(10, 0, 0, 6, 11, 2026);
      $Schedule->recover($now);

      yield assert(
         assertion: $ranOnce === 1,
         description: 'Catchups::Once runs exactly once for missed runs'
      );
      yield assert(
         assertion: $ranSkip === 0,
         description: 'Catchups::Skip does not run missed jobs'
      );

      $After = new State();
      yield assert(
         assertion: $After->fetch('cu_skip') === $now,
         description: 'Skip advances the baseline to now'
      );
      yield assert(
         assertion: $After->fetch('cu_once') === $now,
         description: 'Once records the run at now'
      );
   }
);
