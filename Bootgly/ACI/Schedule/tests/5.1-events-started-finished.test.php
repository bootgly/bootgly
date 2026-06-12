<?php

use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Schedule;
use Bootgly\ACI\Schedule\Events;
use Bootgly\ACI\Schedule\Job;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Dispatch emits Started then Finished with the expected payloads',
   test: function () {
      $Emitter = Emitter::$Instance;

      $started = null;
      $finished = null;
      $Emitter->listen(Events::Started, function (Emission $E) use (&$started) { $started = $E->payload; });
      $Emitter->listen(Events::Finished, function (Emission $E) use (&$finished) { $finished = $E->payload; });

      $Schedule = new Schedule();
      $Schedule->add('evt', fn () => null)->repeat('* * * * *');
      $Schedule->tick(mktime(10, 0, 0, 6, 11, 2026));

      yield assert(
         assertion: $started !== null && $started[0] === 'evt',
         description: 'Started carries the job id'
      );
      yield assert(
         assertion: $started !== null && $started[1] instanceof Job,
         description: 'Started carries the Job'
      );
      yield assert(
         assertion: $finished !== null && $finished[0] === 'evt',
         description: 'Finished carries the job id'
      );
      yield assert(
         assertion: $finished !== null && is_float($finished[1]),
         description: 'Finished carries the duration in milliseconds'
      );
   }
);
