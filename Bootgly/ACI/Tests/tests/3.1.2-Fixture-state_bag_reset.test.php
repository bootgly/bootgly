<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Fixture;
use Bootgly\ACI\Tests\Fixture\Lifecycles;
use Bootgly\ACI\Tests\Suite\Test\Specification;


$Fix = new class (['count' => 0, 'token' => 'abc']) extends Fixture {};

return new Specification(
   description: 'Fixture::state — bag is seeded, mutable, and reset() restores the seed',

   test: new Assertions(Case: function () use ($Fix): Generator {
      yield (new Assertion(description: 'initial bag holds the seed'))
         ->expect($Fix->fetch('count'))
         ->to->be(0)
         ->assert();

      // Mutate the bag.
      $Fix->State->update('count', 42);
      $Fix->State->update('token', 'xyz');
      yield (new Assertion(description: 'state is mutable via update()'))
         ->expect($Fix->fetch('count'))
         ->to->be(42)
         ->assert();

      // reset() restores seed and lifecycle.
      $Fix->prepare();   // → Ready
      $Fix->reset();     // → Pristine, bag restored

      yield (new Assertion(description: 'reset() restores the seed bag'))
         ->expect($Fix->fetch('count'))
         ->to->be(0)
         ->assert();

      yield (new Assertion(description: 'reset() returns lifecycle to Pristine'))
         ->expect($Fix->Lifecycle)
         ->to->be(Lifecycles::Pristine)
         ->assert();

      // Ensure prepare() runs again after reset.
      $Fix->prepare();
      yield (new Assertion(description: 'prepare() works again after reset()'))
         ->expect($Fix->Lifecycle)
         ->to->be(Lifecycles::Ready)
         ->assert();
   })
);
