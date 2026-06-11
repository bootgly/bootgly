<?php

use Bootgly\ABI\Event;
use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\API\Projects\Project\Events;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Project\Events::Boot/Shutdown dispatch as Event identities with payload',
   test: function () {
      // ! Fresh bus — Project::boot()/__destruct() emit through Emitter::$Instance.
      //   boot() defines BOOTGLY_PROJECT once per process and shutdown is GC-bound,
      //   so the real emits are exercised by `bootgly project ...` runs; here the
      //   contract is tested.
      Emitter::$Instance = new Emitter();
      $Emitter = Emitter::$Instance;

      yield assert(
         assertion: Events::Boot instanceof Event && Events::Shutdown instanceof Event,
         description: 'Project.Boot/Shutdown implement Bootgly\ABI\Event'
      );

      $events = [];
      $Emitter->listen(Events::Boot, function (Emission $Emission) use (&$events) {
         $events[] = ['boot', $Emission->payload];
      });
      $Emitter->listen(Events::Shutdown, function (Emission $Emission) use (&$events) {
         $events[] = ['shutdown', $Emission->payload];
      });

      $Project = (object) ['name' => 'Demo'];
      $Emitter->emit(Events::Boot, $Project);
      $Emitter->emit(Events::Shutdown, $Project);

      yield assert(
         assertion: $events === [
            ['boot', [$Project]],
            ['shutdown', [$Project]],
         ],
         description: 'Boot then Shutdown reach their listeners, in order, with the Project'
      );

      // ! Restore a clean bus for any later suite using the shared instance
      Emitter::$Instance = new Emitter();
   }
);
