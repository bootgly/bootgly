<?php

use Bootgly\ACI\Schedule\Cron;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Cron: "0,30 9-17 * * *" matches the top/half hour during business hours',
   test: function () {
      $Cron = new Cron('0,30 9-17 * * *');

      yield assert(
         assertion: $Cron->check(mktime(9, 0, 0, 6, 11, 2026)) === true,
         description: 'Matches the start of the range (09:00)'
      );
      yield assert(
         assertion: $Cron->check(mktime(17, 30, 0, 6, 11, 2026)) === true,
         description: 'Matches the end of the range (17:30)'
      );
      yield assert(
         assertion: $Cron->check(mktime(9, 15, 0, 6, 11, 2026)) === false,
         description: 'Does not match a minute outside the list'
      );
      yield assert(
         assertion: $Cron->check(mktime(18, 0, 0, 6, 11, 2026)) === false,
         description: 'Does not match an hour outside the range'
      );
      yield assert(
         assertion: $Cron->check(mktime(8, 0, 0, 6, 11, 2026)) === false,
         description: 'Does not match before the range'
      );
   }
);
