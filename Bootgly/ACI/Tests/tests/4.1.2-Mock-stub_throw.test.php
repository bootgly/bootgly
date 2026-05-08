<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Doubles\Mock;
use Bootgly\ACI\Tests\Suite\Test\Specification;


interface MockTarget_4_1_2
{
   public function fail (): void;
}


return new Specification(
   description: 'Mock — stub throw rethrows the configured exception',

   test: new Assertions(Case: function (): Generator {
      $Mock = new Mock(MockTarget_4_1_2::class);
      $Mock->stub('fail')->throw(new RuntimeException('boom'));

      $caught = null;
      try {
         $Mock->Proxy->fail();
      }
      catch (RuntimeException $Throwable) {
         $caught = $Throwable;
      }

      yield (new Assertion(description: 'configured exception is rethrown'))
         ->expect($caught?->getMessage())
         ->to->be('boom')
         ->assert();

      yield (new Assertion(description: 'throwing call is still recorded'))
         ->expect($Mock->Calls->count('fail'))
         ->to->be(1)
         ->assert();

      yield (new Assertion(description: 'recorded Call carries the Throwable'))
         ->expect($Mock->Calls->list[0]->Threw instanceof RuntimeException)
         ->to->be(true)
         ->assert();
   })
);
