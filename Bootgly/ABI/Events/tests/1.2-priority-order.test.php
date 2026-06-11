<?php

use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ABI\Events\tests\Events;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/Events.php';


return new Specification(
   description: 'Emitter: listeners run in descending priority order, registration order breaks ties',
   test: function () {
      $Emitter = new Emitter();

      $order = [];
      $Emitter->listen(Events::Alpha, function (Emission $Emission) use (&$order) {
         $order[] = 'low';
      }, priority: 0);
      $Emitter->listen(Events::Alpha, function (Emission $Emission) use (&$order) {
         $order[] = 'high';
      }, priority: 10);
      $Emitter->listen(Events::Alpha, function (Emission $Emission) use (&$order) {
         $order[] = 'mid';
      }, priority: 5);

      $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $order === ['high', 'mid', 'low'],
         description: 'Higher priority listeners run first'
      );
   }
);
