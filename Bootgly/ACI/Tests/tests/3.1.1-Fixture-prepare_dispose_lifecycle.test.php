<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Fixture;
use Bootgly\ACI\Tests\Fixture\Lifecycles;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Fixture lifecycle — prepare()/dispose() are idempotent and update Lifecycles',

   Fixture: new class extends Fixture {
      public int $prepared = 0;
      public int $tornDown = 0;

      protected function setup (): void
      {
         $this->prepared++;
         $this->State->update('prepared', $this->prepared);
         $this->State->update('ready', true);
      }
      protected function teardown (): void
      {
         $this->tornDown++;
         parent::teardown();
      }
   },

   test: new Assertions(Case: function (Fixture $Fixture): Generator {
      // After Test::pretest() called prepare() once, fixture must be Ready.
      yield (new Assertion(description: 'prepare() reaches Lifecycles::Ready'))
         ->expect($Fixture->Lifecycle)
         ->to->be(Lifecycles::Ready)
         ->assert();

      yield (new Assertion(description: 'setup() ran exactly once'))
         ->expect($Fixture->fetch('prepared'))
         ->to->be(1)
         ->assert();

      yield (new Assertion(description: 'state was seeded by setup()'))
         ->expect($Fixture->fetch('ready'))
         ->to->be(true)
         ->assert();

      // Calling prepare() again is a no-op (idempotency guard).
      $Fixture->prepare();
      yield (new Assertion(description: 'second prepare() does not re-run setup()'))
         ->expect($Fixture->fetch('prepared'))
         ->to->be(1)
         ->assert();
   })
);
