<?php

use Bootgly\ABI\Event;
use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ADI\Databases\SQL\Schema\Migration\Events;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Migration\Events: Up/Down dispatch as Event identities with [migration, batch] payload',
   test: function () {
      // ! Fresh bus — Runner::apply() emits through the shared Emitter::$Instance.
      //   Driving a real migration needs a live DB (10.2 covers that); here the
      //   event identities + dispatch contract are tested.
      Emitter::$Instance = new Emitter();
      $Emitter = Emitter::$Instance;

      yield assert(
         assertion: Events::Up instanceof Event && Events::Down instanceof Event,
         description: 'Migration events implement Bootgly\ABI\Event'
      );

      $events = [];
      $Emitter->listen(Events::Up, function (Emission $Emission) use (&$events) {
         $events[] = ['up', $Emission->payload];
      });
      $Emitter->listen(Events::Down, function (Emission $Emission) use (&$events) {
         $events[] = ['down', $Emission->payload];
      });

      $Migration = (object) ['name' => '2026_create_users'];
      $Emitter->emit(Events::Up, $Migration, 3);
      $Emitter->emit(Events::Down, $Migration, 3);

      yield assert(
         assertion: $events === [
            ['up', [$Migration, 3]],
            ['down', [$Migration, 3]],
         ],
         description: 'Up/Down reach only their listeners, in order, with [migration, batch]'
      );

      // ! Restore a clean bus for any later suite using the shared instance
      Emitter::$Instance = new Emitter();
   }
);
