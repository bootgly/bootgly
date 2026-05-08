<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Doubles\Spy;
use Bootgly\ACI\Tests\Suite\Test\Specification;


class SpyTarget_5_1_1
{
   public int $touched = 0;

   public function bump (int $by = 1): int
   {
      $this->touched += $by;
      return $this->touched;
   }
}


return new Specification(
   description: 'Spy — wraps real instance, delegates, records every call',

   test: new Assertions(Case: function (): Generator {
      $Real = new SpyTarget_5_1_1();
      $Spy = new Spy($Real);

      yield (new Assertion(description: 'Wrapped is instanceof real class'))
         ->expect($Spy->Wrapped instanceof SpyTarget_5_1_1)
         ->to->be(true)
         ->assert();

      $result = $Spy->Wrapped->bump(3);

      yield (new Assertion(description: 'call delegates to real instance'))
         ->expect($result)
         ->to->be(3)
         ->assert();

      yield (new Assertion(description: 'real state was mutated'))
         ->expect($Real->touched)
         ->to->be(3)
         ->assert();

      $Spy->Wrapped->bump(2);

      yield (new Assertion(description: 'verify counts delegations'))
         ->expect($Spy->verify('bump', times: 2))
         ->to->be(true)
         ->assert();

      yield (new Assertion(description: 'recorded args preserved'))
         ->expect($Spy->Calls->list[1]->arguments)
         ->to->be([2])
         ->assert();

      yield (new Assertion(description: 'recorded return preserved'))
         ->expect($Spy->Calls->list[1]->returned)
         ->to->be(5)
         ->assert();
   })
);
