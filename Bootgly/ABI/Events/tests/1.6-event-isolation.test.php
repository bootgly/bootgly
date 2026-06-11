<?php

use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ABI\Events\tests\Events;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/Events.php';


return new Specification(
   description: 'Emitter: distinct enum cases keep separate listener sets',
   test: function () {
      $Emitter = new Emitter();

      $hits = ['alpha' => 0, 'beta' => 0];
      $Emitter->listen(Events::Alpha, function (Emission $Emission) use (&$hits) {
         $hits['alpha']++;
      });
      $Emitter->listen(Events::Beta, function (Emission $Emission) use (&$hits) {
         $hits['beta']++;
      });

      $Emitter->emit(Events::Alpha);
      $Emitter->emit(Events::Alpha);
      $Emitter->emit(Events::Beta);

      yield assert(
         assertion: $hits === ['alpha' => 2, 'beta' => 1],
         description: 'Each event only triggers its own listeners'
      );
   }
);
