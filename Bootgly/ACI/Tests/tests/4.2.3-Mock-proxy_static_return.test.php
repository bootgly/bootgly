<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Doubles\Mock;
use Bootgly\ACI\Tests\Suite\Test\Specification;


class MockTarget_4_2_3
{
   public function chain (): static
   {
      return $this;
   }
}


return new Specification(
   description: 'Mock Proxy — preserves static return type',

   test: new Assertions(Case: function (): Generator {
      $Target = new Mock(MockTarget_4_2_3::class);
      $Target->stub('chain', $Target->Proxy);

      yield (new Assertion(description: 'stubbed static return accepts proxy instance'))
         ->expect($Target->Proxy->chain() === $Target->Proxy)
         ->to->be(true)
         ->assert();
   })
);
