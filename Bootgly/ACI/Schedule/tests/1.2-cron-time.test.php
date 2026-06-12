<?php

use Bootgly\ACI\Schedule\Cron;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Cron: "30 9 * * *" matches only at 09:30',
   test: function () {
      $Cron = new Cron('30 9 * * *');

      yield assert(
         assertion: $Cron->check(mktime(9, 30, 0, 6, 11, 2026)) === true,
         description: 'Matches at the exact hour and minute'
      );
      yield assert(
         assertion: $Cron->check(mktime(9, 31, 0, 6, 11, 2026)) === false,
         description: 'Does not match one minute later'
      );
      yield assert(
         assertion: $Cron->check(mktime(10, 30, 0, 6, 11, 2026)) === false,
         description: 'Does not match the same minute in a different hour'
      );
   }
);
