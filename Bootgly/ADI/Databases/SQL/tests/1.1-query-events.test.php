<?php

use Bootgly\ABI\Event;
use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\SQL\Events;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'SQL\Events::Executed fires once when an Operation resolves, carrying the Operation',
   test: function () {
      // ! Fresh bus — Operation emits through the shared Emitter::$Instance
      Emitter::$Instance = new Emitter();
      $Emitter = Emitter::$Instance;

      yield assert(
         assertion: Events::Executed instanceof Event,
         description: 'SQL.Executed is an Event identity (implements Bootgly\ABI\Event)'
      );

      $executed = [];
      $Emitter->listen(Events::Executed, function (Emission $Emission) use (&$executed) {
         $executed[] = $Emission->payload;
      });

      // @ Resolve a query operation in-process (no DB) — the real emit site
      $Operation = new Operation(null, 'SELECT 1 AS ok', [], 0.0);
      $Returned = $Operation->resolve(new Result('SELECT 1', [['ok' => 1]]));

      yield assert(
         assertion: $Returned === $Operation && $Operation->finished === true,
         description: 'resolve() returns the operation and marks it finished'
      );
      yield assert(
         assertion: $executed === [[$Operation]],
         description: 'SQL.Executed fired exactly once, carrying the resolved Operation'
      );

      // ! Restore a clean bus for any later suite using the shared instance
      Emitter::$Instance = new Emitter();
   }
);
