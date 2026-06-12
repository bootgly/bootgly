<?php

use Bootgly\ACI\Schedule\Cron;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Cron: "* * * * *" matches every minute; advance() returns the next whole minute',
   test: function () {
      $Cron = new Cron('* * * * *');

      $ts = mktime(13, 42, 17, 6, 11, 2026);

      yield assert(
         assertion: $Cron->check($ts) === true,
         description: 'Wildcard expression matches any timestamp'
      );
      yield assert(
         assertion: $Cron->expression === '* * * * *',
         description: 'Cron exposes its raw expression'
      );

      $next = $Cron->advance($ts);

      yield assert(
         assertion: $next === ($ts - ($ts % 60) + 60),
         description: 'advance() returns the next whole minute after $from'
      );
      yield assert(
         assertion: ($next % 60) === 0,
         description: 'advance() is aligned to a minute boundary'
      );
   }
);
