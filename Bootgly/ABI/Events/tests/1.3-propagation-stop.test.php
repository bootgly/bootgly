<?php

use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ABI\Events\tests\Events;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/Events.php';


return new Specification(
   description: 'Emitter: Emission->stop() halts remaining listeners',
   test: function () {
      $Emitter = new Emitter();

      $ran = [];
      $Emitter->listen(Events::Alpha, function (Emission $Emission) use (&$ran) {
         $ran[] = 'first';
         $Emission->stop();
      }, priority: 10);
      $Emitter->listen(Events::Alpha, function (Emission $Emission) use (&$ran) {
         $ran[] = 'second';
      }, priority: 0);

      $Emission = $Emitter->emit(Events::Alpha);

      yield assert(
         assertion: $ran === ['first'],
         description: 'Listener after stop() does not run'
      );
      yield assert(
         assertion: $Emission instanceof Emission && $Emission->stopped === true,
         description: 'Emission reports stopped propagation'
      );
   }
);
