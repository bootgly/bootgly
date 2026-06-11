<?php

use Bootgly\ABI\Event;
use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Process\Events as Worker;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Process\Events: Worker Boot/Shutdown/Reload dispatch through Emitter::$Instance with payload',
   test: function () {
      // ! Fresh bus — the server emits through the shared Emitter::$Instance
      Emitter::$Instance = new Emitter();
      $Emitter = Emitter::$Instance;

      yield assert(
         assertion: Worker::Boot instanceof Event,
         description: 'Worker events are Event identities (implement Bootgly\ABI\Event)'
      );

      $events = [];
      $Emitter->listen(Worker::Boot, function (Emission $Emission) use (&$events) {
         $events[] = ['boot', $Emission->payload];
      });
      $Emitter->listen(Worker::Shutdown, function (Emission $Emission) use (&$events) {
         $events[] = ['shutdown', $Emission->payload];
      });
      $Emitter->listen(Worker::Reload, function (Emission $Emission) use (&$events) {
         $events[] = ['reload', $Emission->payload];
      });

      // @ Mirror the server's emit sites
      $Emitter->emit(Worker::Boot, 0);
      $Emitter->emit(Worker::Shutdown, 'child');
      $Emitter->emit(Worker::Reload, 2);

      yield assert(
         assertion: $events === [
            ['boot', [0]],
            ['shutdown', ['child']],
            ['reload', [2]],
         ],
         description: 'Each Worker event reaches only its listener, in order, with payload'
      );

      // ? Distinct cases keep separate listener sets
      yield assert(
         assertion: $Emitter->check(Worker::Boot) && $Emitter->check(Worker::Reload),
         description: 'Boot and Reload have independent registrations'
      );

      // ! Restore a clean bus for any later suite using the shared instance
      Emitter::$Instance = new Emitter();
   }
);
