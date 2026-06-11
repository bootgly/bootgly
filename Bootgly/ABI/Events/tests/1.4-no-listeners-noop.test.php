<?php

use Bootgly\ABI\Events\Emitter;
use Bootgly\ABI\Events\tests\Events;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/Events.php';


return new Specification(
   description: 'Emitter: emit() with no listeners returns null (zero-allocation path)',
   test: function () {
      $Emitter = new Emitter();

      yield assert(
         assertion: $Emitter->emit(Events::Alpha, 'payload') === null,
         description: 'Unobserved event returns null'
      );

      // Registering on one event must not make a sibling event dispatch.
      $Emitter->listen(Events::Beta, fn () => null);

      yield assert(
         assertion: $Emitter->emit(Events::Alpha) === null,
         description: 'Listener on a different event does not observe this one'
      );
   }
);
