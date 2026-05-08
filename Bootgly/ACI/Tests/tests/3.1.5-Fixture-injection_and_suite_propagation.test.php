<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Fixture;
use Bootgly\ACI\Tests\Suite;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Fixture should be injected into tests and propagated by Suite',

   test: new Assertions(Case: function (): Generator {
      $CaseFixture = new class extends Fixture {};
      $Received = null;
      $Spec = new Specification(
         Fixture: $CaseFixture,
         test: new Assertions(Case: function (Fixture $Fixture) use (&$Received): Generator {
            $Received = $Fixture;

            yield true;
         })
      );
      $Spec->index(case: 1);

      $Suite = new Suite(tests: [], autoReport: false, suiteName: 'Fixture injection probe');
      $Test = $Suite->test($Spec);
      if ($Test === null) {
         throw new LogicException('Could not create fixture injection probe test.');
      }

      $Test->test();

      yield (new Assertion(description: 'Assertions closure receives the Specification fixture'))
         ->expect($Received)
         ->to->be($CaseFixture)
         ->assert();

      $DirectFixture = new class extends Fixture {};
      $DirectReceived = null;
      $DirectSpec = new Specification(
         Fixture: $DirectFixture,
         test: function (Fixture $Fixture) use (&$DirectReceived): bool {
            $DirectReceived = $Fixture;

            return true;
         }
      );
      $DirectSpec->index(case: 1);

      $DirectSuite = new Suite(tests: [], autoReport: false, suiteName: 'Fixture direct injection probe');
      $DirectTest = $DirectSuite->test($DirectSpec);
      if ($DirectTest === null) {
         throw new LogicException('Could not create direct fixture injection probe test.');
      }

      $DirectTest->test();

      yield (new Assertion(description: 'direct Closure receives the Specification fixture'))
         ->expect($DirectReceived)
         ->to->be($DirectFixture)
         ->assert();

      $SuiteFixture = new class extends Fixture {
         public int $prepared = 0;

         protected function setup (): void
         {
            $this->prepared++;
            $this->State->update('prepared', $this->prepared);
         }
      };
      $FirstPrepared = null;
      $SecondPrepared = null;
      $Suite = new Suite(
         tests: [],
         autoReport: false,
         suiteName: 'Fixture suite propagation probe',
         Fixture: $SuiteFixture,
      );

      $FirstSpec = new Specification(
         test: new Assertions(Case: function (Fixture $Fixture) use (&$FirstPrepared): Generator {
            $FirstPrepared = $Fixture->fetch('prepared');

            yield true;
         })
      );
      $FirstSpec->index(case: 1);
      $FirstTest = $Suite->test($FirstSpec);
      if ($FirstTest === null) {
         throw new LogicException('Could not create first suite fixture probe test.');
      }
      $FirstTest->test();

      $SecondSpec = new Specification(
         test: new Assertions(Case: function (Fixture $Fixture) use (&$SecondPrepared): Generator {
            $SecondPrepared = $Fixture->fetch('prepared');

            yield true;
         })
      );
      $SecondSpec->index(case: 2);
      $SecondTest = $Suite->test($SecondSpec);
      if ($SecondTest === null) {
         throw new LogicException('Could not create second suite fixture probe test.');
      }
      $SecondTest->test();

      yield (new Assertion(description: 'Suite propagates its fixture to the first Specification'))
         ->expect($FirstSpec->Fixture)
         ->to->be($SuiteFixture)
         ->assert();

      yield (new Assertion(description: 'Suite propagates its fixture to the second Specification'))
         ->expect($SecondSpec->Fixture)
         ->to->be($SuiteFixture)
         ->assert();

      yield (new Assertion(description: 'shared Suite fixture prepares the first case'))
         ->expect($FirstPrepared)
         ->to->be(1)
         ->assert();

      yield (new Assertion(description: 'disposed Suite fixture is reset before the next case'))
         ->expect($SecondPrepared)
         ->to->be(2)
         ->assert();

      yield (new Assertion(description: 'Suite fixture setup ran once per propagated case'))
         ->expect($SuiteFixture->prepared)
         ->to->be(2)
         ->assert();
   })
);
