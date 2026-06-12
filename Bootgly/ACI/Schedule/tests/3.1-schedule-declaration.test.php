<?php

use Bootgly\ACI\Schedule;
use Bootgly\ACI\Schedule\Catchups;
use Bootgly\ACI\Schedule\Cron;
use Bootgly\ACI\Schedule\Frequencies;
use Bootgly\ACI\Schedule\Job;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Schedule::add() registers a Job; repeat()/lock()/recover() build it fluently',
   test: function () {
      $Schedule = new Schedule();

      $Job = $Schedule->add('demo', fn () => null);

      yield assert(
         assertion: $Job instanceof Job,
         description: 'add() returns the Job'
      );
      yield assert(
         assertion: $Schedule->Jobs === [$Job],
         description: 'The Job is registered in $Jobs'
      );
      yield assert(
         assertion: $Job->id === 'demo',
         description: 'The Job carries its identity'
      );

      $returned = $Job
         ->repeat(Frequencies::Daily, at: '03:00')
         ->lock()
         ->recover(Catchups::Once);

      yield assert(
         assertion: $returned === $Job,
         description: 'Fluent methods return the same Job'
      );
      yield assert(
         assertion: $Job->Cron instanceof Cron && $Job->Cron->expression === '0 3 * * *',
         description: 'repeat() resolves the cadence to a Cron'
      );
      yield assert(
         assertion: $Job->locked === true,
         description: 'lock() enables overlap prevention'
      );
      yield assert(
         assertion: $Job->Catchup === Catchups::Once,
         description: 'recover() sets the catch-up policy'
      );

      // @ Raw cron string via the same verb
      $Raw = $Schedule->add('raw', fn () => null)->repeat('*/5 * * * *');
      yield assert(
         assertion: $Raw->Cron->expression === '*/5 * * * *',
         description: 'repeat() accepts a raw cron string'
      );
   }
);
