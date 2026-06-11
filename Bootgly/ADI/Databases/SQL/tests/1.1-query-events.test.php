<?php

use Bootgly\ABI\Event;
use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\SQL\Events;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'SQL\Events: Executed/Slow fire from Operation::resolve(); Connected is a dispatchable identity',
   test: function () {
      // ! Fresh bus — Operation emits through the shared Emitter::$Instance
      Emitter::$Instance = new Emitter();
      $Emitter = Emitter::$Instance;
      Operation::$slow = 0.0;

      yield assert(
         assertion: Events::Connected instanceof Event
            && Events::Executed instanceof Event
            && Events::Slow instanceof Event,
         description: 'Connected/Executed/Slow implement Bootgly\ABI\Event'
      );

      $executed = [];
      $slow = [];
      $Emitter->listen(Events::Executed, function (Emission $Emission) use (&$executed) {
         $executed[] = $Emission->payload;
      });
      $Emitter->listen(Events::Slow, function (Emission $Emission) use (&$slow) {
         $slow[] = $Emission->payload;
      });

      // @ Executed — slow detection disabled ($slow = 0.0): no Slow, zero microtime()
      $Operation = new Operation(null, 'SELECT 1 AS ok', [], 0.0);
      $Returned = $Operation->resolve(new Result('SELECT 1', [['ok' => 1]]));
      yield assert(
         assertion: $Returned === $Operation && $Operation->finished === true,
         description: 'resolve() returns the operation and marks it finished'
      );
      yield assert(
         assertion: $executed === [[$Operation]] && $slow === [],
         description: 'SQL.Executed fired once; SQL.Slow did NOT (detection disabled)'
      );

      // @ Slow — tiny threshold so any elapsed time triggers it
      Operation::$slow = 0.000_000_001;
      $Slow = new Operation(null, 'SELECT slow', [], 0.0);
      $Slow->resolve(new Result('SELECT', []));
      Operation::$slow = 0.0;
      yield assert(
         assertion: count($slow) === 1 && $slow[0][0] === $Slow && $slow[0][1] > 0.0,
         description: 'SQL.Slow fired once with [Operation, elapsed > 0] when over threshold'
      );

      // ! Restore a clean bus for any later suite using the shared instance
      Emitter::$Instance = new Emitter();
   }
);
