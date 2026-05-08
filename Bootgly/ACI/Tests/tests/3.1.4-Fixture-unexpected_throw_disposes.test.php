<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Fixture;
use Bootgly\ACI\Tests\Fixture\Lifecycles;
use Bootgly\ACI\Tests\Suite;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Fixture lifecycle — unexpected test Throwable still disposes the fixture',

   test: new Assertions(Case: function (): Generator {
      $Fix = new class extends Fixture {
         public int $disposed = 0;

         protected function teardown (): void
         {
            $this->disposed++;
            parent::teardown();
         }
      };

      $Spec = new Specification(
         description: 'Fixture throwable probe',
         Fixture: $Fix,
         test: static function (): bool {
            throw new RuntimeException('unexpected boom');
         },
      );
      $Spec->index(case: 1);

      $Suite = new Suite(tests: [], autoReport: false, suiteName: 'Fixture throwable probe');
      $Test = $Suite->test($Spec);
      if ($Test === null) {
         throw new LogicException('Could not create fixture throwable probe test.');
      }

      $message = null;
      try {
         $Test->test();
      }
      catch (RuntimeException $Exception) {
         $message = $Exception->getMessage();
      }

      yield (new Assertion(description: 'unexpected Throwable is rethrown'))
         ->expect($message)
         ->to->be('unexpected boom')
         ->assert();

      yield (new Assertion(description: 'fixture teardown ran once'))
         ->expect($Fix->disposed)
         ->to->be(1)
         ->assert();

      yield (new Assertion(description: 'fixture lifecycle reaches Disposed'))
         ->expect($Fix->Lifecycle)
         ->to->be(Lifecycles::Disposed)
         ->assert();
   })
);
