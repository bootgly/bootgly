<?php

use Bootgly\ABI\Event;
use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Events as RequestEvents;


return new Specification(
   description: 'HTTP Request events: Received/Handled dispatch as Event identities with payload',
   test: function () {
      // ! Fresh bus — the encoders emit through the shared Emitter::$Instance
      Emitter::$Instance = new Emitter();
      $Emitter = Emitter::$Instance;

      yield assert(
         assertion: RequestEvents::Received instanceof Event
            && RequestEvents::Handled instanceof Event,
         description: 'HTTP request events implement Bootgly\ABI\Event'
      );

      $events = [];
      $Emitter->listen(RequestEvents::Received, function (Emission $Emission) use (&$events) {
         $events[] = ['received', $Emission->payload];
      });
      $Emitter->listen(RequestEvents::Handled, function (Emission $Emission) use (&$events) {
         $events[] = ['handled', $Emission->payload];
      });

      // @ Mirror the encoder's emit sites (request decoded -> request handled)
      $Request  = (object) ['uri' => '/'];
      $Response = (object) ['code' => 200];
      $Emitter->emit(RequestEvents::Received, $Request);
      $Emitter->emit(RequestEvents::Handled, $Request, $Response);

      yield assert(
         assertion: $events === [
            ['received', [$Request]],
            ['handled', [$Request, $Response]],
         ],
         description: 'Each request event reaches only its listener, in order, with payload'
      );

      // ? Received and Handled keep separate registrations
      yield assert(
         assertion: $Emitter->check(RequestEvents::Received) && $Emitter->check(RequestEvents::Handled),
         description: 'Request.Received and Request.Handled are independent identities'
      );

      // ! Restore a clean bus for any later suite using the shared instance
      Emitter::$Instance = new Emitter();
   }
);
