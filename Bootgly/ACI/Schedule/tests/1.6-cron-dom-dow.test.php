<?php

use Bootgly\ACI\Schedule\Cron;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Cron: when both day-of-month and day-of-week are set, EITHER may match (Vixie semantics)',
   test: function () {
      // @ Run on the 13th OR on any Monday
      $Cron = new Cron('* * 13 * 1');

      // # The 13th (regardless of weekday) matches via day-of-month
      yield assert(
         assertion: $Cron->check(mktime(0, 0, 0, 6, 13, 2026)) === true,
         description: 'Matches on the 13th via day-of-month'
      );

      // # A Monday that is not the 13th matches via day-of-week
      $monday = mktime(0, 0, 0, 6, 1, 2026);
      while ((int) date('w', $monday) !== 1 || (int) date('j', $monday) === 13) {
         $monday += 86400;
      }
      yield assert(
         assertion: $Cron->check($monday) === true,
         description: 'Matches on a Monday via day-of-week'
      );

      // # A day that is neither the 13th nor a Monday must not match
      $neither = mktime(0, 0, 0, 6, 1, 2026);
      while ((int) date('w', $neither) === 1 || (int) date('j', $neither) === 13) {
         $neither += 86400;
      }
      yield assert(
         assertion: $Cron->check($neither) === false,
         description: 'Does not match a day that is neither the 13th nor a Monday'
      );
   }
);
