<?php

use Bootgly\ABI\Event;
use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ADI\Databases\SQL\Transaction\Events;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'SQL Transaction\Events: Begin/Commit/Rollback dispatch as Event identities with payload',
   test: function () {
      // ! Fresh bus — Transaction emits through the shared Emitter::$Instance.
      //   Driving a real begin()/commit() needs a live connection (DB suites
      //   cover that); here the event identities + dispatch contract are tested.
      Emitter::$Instance = new Emitter();
      $Emitter = Emitter::$Instance;

      yield assert(
         assertion: Events::Begin instanceof Event
            && Events::Commit instanceof Event
            && Events::Rollback instanceof Event,
         description: 'Transaction events implement Bootgly\ABI\Event'
      );

      $events = [];
      $Emitter->listen(Events::Begin, function (Emission $Emission) use (&$events) {
         $events[] = ['begin', $Emission->payload];
      });
      $Emitter->listen(Events::Commit, function (Emission $Emission) use (&$events) {
         $events[] = ['commit', $Emission->payload];
      });
      $Emitter->listen(Events::Rollback, function (Emission $Emission) use (&$events) {
         $events[] = ['rollback', $Emission->payload];
      });

      $Transaction = (object) ['depth' => 1];
      $Emitter->emit(Events::Begin, $Transaction);
      $Emitter->emit(Events::Commit, $Transaction);
      $Emitter->emit(Events::Rollback, $Transaction);

      yield assert(
         assertion: $events === [
            ['begin', [$Transaction]],
            ['commit', [$Transaction]],
            ['rollback', [$Transaction]],
         ],
         description: 'Each transaction event reaches only its listener, in order, with payload'
      );

      // ! Restore a clean bus for any later suite using the shared instance
      Emitter::$Instance = new Emitter();
   }
);
