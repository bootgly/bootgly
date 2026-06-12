<?php

use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Schedule;
use Bootgly\ACI\Schedule\Events;
use Bootgly\ACI\Schedule\Lock;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'A locked job whose lock is already held does not run and emits Skipped("overlap")',
   test: function () {
      $Emitter = Emitter::$Instance;

      $skipped = null;
      $Emitter->listen(Events::Skipped, function (Emission $E) use (&$skipped) { $skipped = $E->payload; });

      // ! Hold the lock externally to force an overlap
      $Held = new Lock('overlap_evt');
      $Held->acquire();

      $ran = 0;

      $Schedule = new Schedule();
      $Schedule->add('overlap_evt', function () use (&$ran) { $ran++; })
         ->repeat('* * * * *')
         ->lock();

      $Schedule->tick(mktime(10, 0, 0, 6, 11, 2026));

      yield assert(
         assertion: $ran === 0,
         description: 'The job does not run while the lock is held'
      );
      yield assert(
         assertion: $skipped !== null && $skipped[0] === 'overlap_evt' && $skipped[1] === 'overlap',
         description: 'Skipped is emitted with the "overlap" reason'
      );

      // @ Cleanup
      $Held->release();
   }
);
