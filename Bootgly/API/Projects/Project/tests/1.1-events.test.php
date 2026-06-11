<?php

use Bootgly\ABI\Event;
use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\API\Projects\Project\Events;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Project\Events::Boot dispatches as an Event identity with payload',
   test: function () {
      // ! Fresh bus — Project::boot() emits through the shared Emitter::$Instance.
      //   boot() defines BOOTGLY_PROJECT once per process, so the real emit is
      //   exercised by any `bootgly project ...` run; here the contract is tested.
      Emitter::$Instance = new Emitter();
      $Emitter = Emitter::$Instance;

      yield assert(
         assertion: Events::Boot instanceof Event,
         description: 'Project.Boot is an Event identity (implements Bootgly\ABI\Event)'
      );

      $booted = [];
      $Emitter->listen(Events::Boot, function (Emission $Emission) use (&$booted) {
         $booted[] = $Emission->payload;
      });

      $Project = (object) ['name' => 'Demo'];
      $Emitter->emit(Events::Boot, $Project);

      yield assert(
         assertion: $booted === [[$Project]],
         description: 'Project.Boot fired once with the Project'
      );

      // ! Restore a clean bus for any later suite using the shared instance
      Emitter::$Instance = new Emitter();
   }
);
