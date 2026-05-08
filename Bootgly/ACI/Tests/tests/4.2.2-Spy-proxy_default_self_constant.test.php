<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Doubles\Spy;
use Bootgly\ACI\Tests\Suite\Test\Specification;


class SpyTarget_4_2_2
{
   public const TOKEN = 'self-token';

   public function token (string $value = self::TOKEN): string
   {
      return $value;
   }
}


return new Specification(
   description: 'Spy Proxy — compiles default self constants',

   test: new Assertions(Case: function (): Generator {
      $Spy = new Spy(new SpyTarget_4_2_2());

      yield (new Assertion(description: 'default self constant remains valid in generated proxy'))
         ->expect($Spy->Wrapped->token())
         ->to->be('self-token')
         ->assert();
   })
);
