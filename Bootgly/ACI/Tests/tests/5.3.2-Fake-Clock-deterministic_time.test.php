<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Doubles\Fake\Clock;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Fake Clock — deterministic time substitute',

   test: new Assertions(Case: function (): Generator {
      $Clock = new Clock(100);

      yield (new Assertion(description: 'clock starts at configured timestamp'))
         ->expect($Clock->now)
         ->to->be(100.0)
         ->assert();

      $Clock->advance(2);

      yield (new Assertion(description: 'advance moves time forward'))
         ->expect($Clock->now)
         ->to->be(102.0)
         ->assert();

      $Clock->advance(0.75);

      yield (new Assertion(description: 'advance supports fractional seconds'))
         ->expect($Clock->now)
         ->to->be(102.75)
         ->assert();

      $Clock->freeze(42.5);

      yield (new Assertion(description: 'freeze sets an exact timestamp'))
         ->expect($Clock->now)
         ->to->be(42.5)
         ->assert();

      yield (new Assertion(description: 'reset returns the same fake'))
         ->expect($Clock->reset())
         ->to->be($Clock)
         ->assert();

      yield (new Assertion(description: 'reset restores initial timestamp'))
         ->expect($Clock->now)
         ->to->be(100.0)
         ->assert();
   })
);
