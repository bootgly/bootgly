<?php

use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ABI\Events\tests\Events;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/Events.php';


return new Specification(
   description: 'Emitter: listen() then emit() dispatches synchronously and delivers the payload',
   test: function () {
      $Emitter = new Emitter();

      $received = [];
      $Emitter->listen(Events::Alpha, function (Emission $Emission) use (&$received) {
         $received = $Emission->payload;
      });

      $Emission = $Emitter->emit(Events::Alpha, 'one', 2, ['three']);

      yield assert(
         assertion: $received === ['one', 2, ['three']],
         description: 'Listener receives the full emit() payload in order'
      );
      yield assert(
         assertion: $Emission instanceof Emission,
         description: 'emit() returns an Emission when listeners exist'
      );
      yield assert(
         assertion: $Emission->Event === Events::Alpha,
         description: 'Emission carries the dispatched event'
      );
      yield assert(
         assertion: $Emission->stopped === false,
         description: 'Emission is not stopped when no listener halts it'
      );
   }
);
