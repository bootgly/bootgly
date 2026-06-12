<?php

use Bootgly\ACI\Schedule;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Schedule::tick() runs only jobs whose cadence is due; jobs without a cadence are skipped',
   test: function () {
      $Schedule = new Schedule();

      $ran = [];

      $Schedule->add('due', function () use (&$ran) { $ran[] = 'due'; })
         ->repeat('* * * * *');                       // always due

      $Schedule->add('idle', function () use (&$ran) { $ran[] = 'idle'; })
         ->repeat('0 0 1 1 *');                        // only Jan 1 00:00

      $Schedule->add('nocron', function () use (&$ran) { $ran[] = 'nocron'; });

      // @ A timestamp that is not Jan 1 00:00
      $Schedule->tick(mktime(10, 30, 0, 6, 11, 2026));

      yield assert(
         assertion: $ran === ['due'],
         description: 'Only the due job ran (idle not due, nocron has no cadence)'
      );
   }
);
