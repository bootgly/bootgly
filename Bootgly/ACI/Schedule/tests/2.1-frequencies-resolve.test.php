<?php

use Bootgly\ACI\Schedule\Cron;
use Bootgly\ACI\Schedule\Frequencies;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Frequencies::resolve() maps named cadences to cron expressions',
   test: function () {
      yield assert(
         assertion: Frequencies::Minutely->resolve() === '* * * * *',
         description: 'Minutely ignores time-of-day'
      );
      yield assert(
         assertion: Frequencies::Hourly->resolve('00:15') === '15 * * * *',
         description: 'Hourly uses only the minute component'
      );
      yield assert(
         assertion: Frequencies::Daily->resolve() === '0 0 * * *',
         description: 'Daily defaults to midnight'
      );
      yield assert(
         assertion: Frequencies::Daily->resolve('03:00') === '0 3 * * *',
         description: 'Daily honours the "HH:MM" time-of-day'
      );
      yield assert(
         assertion: Frequencies::Weekly->resolve('06:30') === '30 6 * * 0',
         description: 'Weekly runs on Sunday at the given time'
      );
      yield assert(
         assertion: Frequencies::Monthly->resolve('00:00') === '0 0 1 * *',
         description: 'Monthly runs on the 1st at the given time'
      );

      // @ Round-trip: a resolved expression parses and matches as expected
      $Cron = new Cron(Frequencies::Daily->resolve('03:00'));
      yield assert(
         assertion: $Cron->check(mktime(3, 0, 0, 6, 11, 2026)) === true,
         description: 'A resolved cadence parses into a matching Cron'
      );
   }
);
