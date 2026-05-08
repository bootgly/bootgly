<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Doubles\Mock;
use Bootgly\ACI\Tests\Suite\Test\Specification;


interface MockTarget_4_1_1
{
   public function check (string $token): bool;
   public function name (): string;
}


return new Specification(
   description: 'Mock — stub return + verify call count',

   test: new Assertions(Case: function (): Generator {
      $Auth = new Mock(MockTarget_4_1_1::class);
      $Auth->stub('check', true);
      $Auth->stub('name', 'alice');

      yield (new Assertion(description: 'Proxy is instanceof target'))
         ->expect($Auth->Proxy instanceof MockTarget_4_1_1)
         ->to->be(true)
         ->assert();

      yield (new Assertion(description: 'stub returns the configured value'))
         ->expect($Auth->Proxy->check('abc'))
         ->to->be(true)
         ->assert();

      yield (new Assertion(description: 'multiple stubs cohabit per method'))
         ->expect($Auth->Proxy->name())
         ->to->be('alice')
         ->assert();

      $Auth->Proxy->check('xyz');

      yield (new Assertion(description: 'verify counts invocations'))
         ->expect($Auth->verify('check', times: 2))
         ->to->be(true)
         ->assert();

      yield (new Assertion(description: 'verify(null) checks for any call'))
         ->expect($Auth->verify('name'))
         ->to->be(true)
         ->assert();

      $Auth->reset();

      yield (new Assertion(description: 'reset() clears recorded calls'))
         ->expect($Auth->Calls->count())
         ->to->be(0)
         ->assert();
   })
);
