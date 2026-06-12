<?php

use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Schedule;
use Bootgly\ACI\Schedule\Events;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'A failing job emits Failed and does not stop the worker; later jobs still run',
   test: function () {
      $Emitter = Emitter::$Instance;

      $failed = null;
      $Emitter->listen(Events::Failed, function (Emission $E) use (&$failed) { $failed = $E->payload; });

      $ranAfter = 0;

      $Schedule = new Schedule();
      $Schedule->add('boom', function () { throw new RuntimeException('boom'); })
         ->repeat('* * * * *');
      $Schedule->add('after', function () use (&$ranAfter) { $ranAfter++; })
         ->repeat('* * * * *');

      $Schedule->tick(mktime(10, 0, 0, 6, 11, 2026));

      yield assert(
         assertion: $failed !== null && $failed[0] === 'boom',
         description: 'Failed carries the job id'
      );
      yield assert(
         assertion: $failed !== null && $failed[1] instanceof Throwable,
         description: 'Failed carries the Throwable'
      );
      yield assert(
         assertion: $ranAfter === 1,
         description: 'A failing job does not stop the worker; later jobs still run'
      );
   }
);
