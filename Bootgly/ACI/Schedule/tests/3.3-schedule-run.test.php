<?php

use Bootgly\ACI\Schedule\Job;
use Bootgly\ACI\Tests\Suite\Test\Specification;


// @ Invokable class-string task probe
final class ScheduleJobProbe
{
   public static int $runs = 0;

   public function __invoke (): void
   {
      self::$runs++;
   }
}


return new Specification(
   description: 'Job::run() executes both Closure and invokable class-string tasks',
   test: function () {
      // # Closure task
      $count = 0;
      $Closure = new Job('closure', function () use (&$count) { $count++; });
      $Closure->run();

      yield assert(
         assertion: $count === 1,
         description: 'run() invokes the Closure task'
      );

      // # Invokable class-string task
      ScheduleJobProbe::$runs = 0;
      $Invokable = new Job('invokable', ScheduleJobProbe::class);
      $Invokable->run();

      yield assert(
         assertion: ScheduleJobProbe::$runs === 1,
         description: 'run() instantiates and invokes the class-string task'
      );
   }
);
