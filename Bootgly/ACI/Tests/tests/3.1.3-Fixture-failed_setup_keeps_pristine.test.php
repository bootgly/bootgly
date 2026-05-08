<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Fixture;
use Bootgly\ACI\Tests\Fixture\Lifecycles;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Fixture::prepare() — failed setup() rewinds lifecycle to Pristine and rethrows',

   test: new Assertions(Case: function (): Generator {
      $Fix = new class extends Fixture {
         protected function setup (): void
         {
            throw new RuntimeException('setup boom');
         }
      };

      $thrown = null;
      try {
         $Fix->prepare();
      }
      catch (RuntimeException $Exception) {
         $thrown = $Exception->getMessage();
      }

      yield (new Assertion(description: 'setup() exception was rethrown'))
         ->expect($thrown)
         ->to->be('setup boom')
         ->assert();

      yield (new Assertion(description: 'lifecycle rolled back to Pristine'))
         ->expect($Fix->Lifecycle)
         ->to->be(Lifecycles::Pristine)
         ->assert();

      // dispose() must be a no-op when fixture never reached Ready.
      $Fix->dispose();
      yield (new Assertion(description: 'dispose() is a no-op when not Ready'))
         ->expect($Fix->Lifecycle)
         ->to->be(Lifecycles::Pristine)
         ->assert();
   })
);
