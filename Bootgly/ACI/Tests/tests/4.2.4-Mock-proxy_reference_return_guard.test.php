<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Doubles\Mock;
use Bootgly\ACI\Tests\Suite\Test\Specification;


class MockTarget_4_2_4
{
   private string $value = 'kept';

   public function &value (): string
   {
      return $this->value;
   }
}


return new Specification(
   description: 'Mock Proxy — rejects by-reference return methods explicitly',

   test: new Assertions(Case: function (): Generator {
      $message = '';

      try {
         new Mock(MockTarget_4_2_4::class);
      }
      catch (LogicException $Exception) {
         $message = $Exception->getMessage();
      }

      yield (new Assertion(description: 'unsupported return-by-reference signature fails before eval'))
         ->expect(str_contains($message, 'by-reference return'))
         ->to->be(true)
         ->assert();
   })
);
