<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Fixture;
use Bootgly\ACI\Tests\Suite;
use Bootgly\ACI\Tests\Suite\Test\Specification;


interface FixtureSignatureProbe_3_1_6 {}


return new Specification(
   description: 'Fixture should prioritize Specification fixtures and inject by signature',

   test: new Assertions(Case: function (): Generator {
      $SuiteFixture = new class extends Fixture {};
      $SpecFixture = new class extends Fixture {};
      $OverrideReceived = null;
      $OverrideSuite = new Suite(
         tests: [],
         autoReport: false,
         suiteName: 'Fixture priority probe',
         Fixture: $SuiteFixture,
      );
      $OverrideSpec = new Specification(
         Fixture: $SpecFixture,
         test: new Assertions(Case: function (Fixture $Fixture) use (&$OverrideReceived): Generator {
            $OverrideReceived = $Fixture;

            yield true;
         })
      );
      $OverrideSpec->index(case: 1);
      $OverrideTest = $OverrideSuite->test($OverrideSpec);
      if ($OverrideTest === null) {
         throw new LogicException('Could not create fixture priority probe test.');
      }
      $OverrideTest->test();

      yield (new Assertion(description: 'Specification fixture overrides Suite fixture'))
         ->expect($OverrideSpec->Fixture)
         ->to->be($SpecFixture)
         ->assert();

      yield (new Assertion(description: 'injected fixture is the Specification fixture'))
         ->expect($OverrideReceived)
         ->to->be($SpecFixture)
         ->assert();

      $ArgumentFixture = new class extends Fixture {};
      $ArgumentReceived = [];
      $ArgumentSpec = new Specification(
         Fixture: $ArgumentFixture,
         test: function (string $value, Fixture $Fixture) use (&$ArgumentReceived): bool {
            $ArgumentReceived = [$value, $Fixture];

            return true;
         }
      );
      $ArgumentSpec->index(case: 1);
      $ArgumentSuite = new Suite(tests: [], autoReport: false, suiteName: 'Fixture argument probe');
      $ArgumentTest = $ArgumentSuite->test($ArgumentSpec);
      if ($ArgumentTest === null) {
         throw new LogicException('Could not create fixture argument probe test.');
      }
      $ArgumentTest->test('payload');

      yield (new Assertion(description: 'runner arguments stay before the injected Fixture'))
         ->expect($ArgumentReceived)
         ->to->be(['payload', $ArgumentFixture])
         ->assert();

      $MismatchFixture = new class extends Fixture {};
      $MismatchValue = null;
      $MismatchSpec = new Specification(
         Fixture: $MismatchFixture,
         test: function (int $value = 7) use (&$MismatchValue): bool {
            $MismatchValue = $value;

            return true;
         }
      );
      $MismatchSpec->index(case: 1);
      $MismatchSuite = new Suite(tests: [], autoReport: false, suiteName: 'Fixture mismatch probe');
      $MismatchTest = $MismatchSuite->test($MismatchSpec);
      if ($MismatchTest === null) {
         throw new LogicException('Could not create fixture mismatch probe test.');
      }
      $MismatchTest->test();

      yield (new Assertion(description: 'Fixture is not injected into incompatible builtin parameters'))
         ->expect($MismatchValue)
         ->to->be(7)
         ->assert();

      $VariadicFixture = new class extends Fixture {};
      $VariadicReceived = [];
      $VariadicSpec = new Specification(
         Fixture: $VariadicFixture,
         test: function (Fixture ...$Fixtures) use (&$VariadicReceived): bool {
            $VariadicReceived = $Fixtures;

            return true;
         }
      );
      $VariadicSpec->index(case: 1);
      $VariadicSuite = new Suite(tests: [], autoReport: false, suiteName: 'Fixture variadic probe');
      $VariadicTest = $VariadicSuite->test($VariadicSpec);
      if ($VariadicTest === null) {
         throw new LogicException('Could not create fixture variadic probe test.');
      }
      $VariadicTest->test();

      yield (new Assertion(description: 'Fixture is injected into compatible variadic parameters'))
         ->expect($VariadicReceived)
         ->to->be([$VariadicFixture])
         ->assert();

      $UnionFixture = new class extends Fixture {};
      $UnionReceived = null;
      $UnionSpec = new Specification(
         Fixture: $UnionFixture,
         test: function (string|Fixture $Fixture) use (&$UnionReceived): bool {
            $UnionReceived = $Fixture;

            return true;
         }
      );
      $UnionSpec->index(case: 1);
      $UnionSuite = new Suite(tests: [], autoReport: false, suiteName: 'Fixture union probe');
      $UnionTest = $UnionSuite->test($UnionSpec);
      if ($UnionTest === null) {
         throw new LogicException('Could not create fixture union probe test.');
      }
      $UnionTest->test();

      yield (new Assertion(description: 'Fixture is injected into compatible union parameters'))
         ->expect($UnionReceived)
         ->to->be($UnionFixture)
         ->assert();

      $IntersectionFixture = new class extends Fixture implements FixtureSignatureProbe_3_1_6 {};
      $IntersectionReceived = null;
      $IntersectionSpec = new Specification(
         Fixture: $IntersectionFixture,
         test: function (Fixture&FixtureSignatureProbe_3_1_6 $Fixture) use (&$IntersectionReceived): bool {
            $IntersectionReceived = $Fixture;

            return true;
         }
      );
      $IntersectionSpec->index(case: 1);
      $IntersectionSuite = new Suite(tests: [], autoReport: false, suiteName: 'Fixture intersection probe');
      $IntersectionTest = $IntersectionSuite->test($IntersectionSpec);
      if ($IntersectionTest === null) {
         throw new LogicException('Could not create fixture intersection probe test.');
      }
      $IntersectionTest->test();

      yield (new Assertion(description: 'Fixture is injected into compatible intersection parameters'))
         ->expect($IntersectionReceived)
         ->to->be($IntersectionFixture)
         ->assert();
   })
);
