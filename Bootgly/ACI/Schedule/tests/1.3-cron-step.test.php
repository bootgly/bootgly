<?php

use Bootgly\ACI\Schedule\Cron;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Cron: "*/20 * * * *" matches minutes 0, 20 and 40 only',
   test: function () {
      $Cron = new Cron('*/20 * * * *');

      yield assert(
         assertion: $Cron->check(mktime(8, 0, 0, 6, 11, 2026)) === true,
         description: 'Matches minute 0'
      );
      yield assert(
         assertion: $Cron->check(mktime(8, 20, 0, 6, 11, 2026)) === true,
         description: 'Matches minute 20'
      );
      yield assert(
         assertion: $Cron->check(mktime(8, 40, 0, 6, 11, 2026)) === true,
         description: 'Matches minute 40'
      );
      yield assert(
         assertion: $Cron->check(mktime(8, 21, 0, 6, 11, 2026)) === false,
         description: 'Does not match a minute outside the step'
      );
   }
);
