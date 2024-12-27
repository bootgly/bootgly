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
      // Normal use
      yield new Assertion(
         description: 'Validating wait time (normal use)',
      )
         ->expect(function () {
            usleep(10000);
         })
         ->to->call()
         ->to->wait(10000)
         ->assert();

      // Closure with Subassertion
      $callable = function () {
         usleep(1000); // Simulates a blocking task
      };
      yield new Assertion(
         description: 'Validating wait time (Closure with Subassertion)',
      )
         ->expect($callable)
         ->to->call()
         ->to->wait(function (float $duration): Assertion {
            $this::$description .= " [{$duration}] ms";

            // implicit ->expect($duration)
            return $this
               ->to->delimit(1000, 20000);
            // implicit ->assert()
         })
         ->assert();
   })
];
