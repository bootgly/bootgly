<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should test using waiters',
   test: new Assertions(Case: function (): Generator
   {
      // Normal use
      yield new Assertion(
         description: 'Validating wait time (normal use)',
      )
         ->expect(function () {
            usleep(10000);
         })
         ->to->call()
         // ! Microseconds, and the budget is now enforced (TESTS-WAIT), so it
         //   has to clear the 10 ms callable PLUS the fork + reap around it —
         //   the same wall-clock the subassertion below measures at ~8 ms here
         //   and ~22 ms on a shared CI runner. 200 ms leaves an order of
         //   magnitude of headroom over that; the over-budget direction is
         //   covered by `1.7.3` rather than by tightening this one.
         ->to->wait(200000)
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
            $this::$description .= " [{$duration} ms]";

            // implicit ->expect($duration)
            // ! The waiter reports wall-clock microseconds around fork + reap,
            //   not the callable alone, so the ceiling only has to stay above
            //   scheduling noise: ~8ms here against ~22ms on a shared CI runner.
            return $this
               ->to->delimit(1000, 200000);
            // implicit ->assert()
         })
         ->assert();
   })
);
