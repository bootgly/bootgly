<?php

use Generator;

use Bootgly\ACI\Tests\Cases\Assertion;
use Bootgly\ACI\Tests\Cases\Assertions;

return [
   // @ configure
   'describe' => 'It should test using waiters',
   // @ simulate
   // ...
   // @ test
   'test' => new Assertions(Case: function (): Generator
   {
      // Callable
      $callable = function () {
         usleep(100); // Simulates a blocking task
      };
      yield new Assertion(
         description: 'Validating wait time',
      )
         ->expect($callable)
         ->to->call()
         ->to->wait(1) // Expecting the callable to execute within 1 seconds
         ->assert();
   })
];