<?php

use Bootgly\ACI\Schedule\Cron;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Cron: advance() finds the next matching timestamp strictly after $from',
   test: function () {
      $Cron = new Cron('*/15 * * * *');

      // @ 10:07:30 → next quarter-hour is 10:15:00
      $from = mktime(10, 7, 30, 6, 11, 2026);
      $next = $Cron->advance($from);

      yield assert(
         assertion: (int) date('i', $next) === 15 && (int) date('G', $next) === 10,
         description: 'advance() lands on 10:15'
      );

      // @ Exactly on a match (10:15:00) → returns the *next* one (10:30:00)
      $onMatch = mktime(10, 15, 0, 6, 11, 2026);
      $after = $Cron->advance($onMatch);

      yield assert(
         assertion: (int) date('i', $after) === 30 && (int) date('G', $after) === 10,
         description: 'advance() is strictly after $from when $from already matches'
      );
   }
);
