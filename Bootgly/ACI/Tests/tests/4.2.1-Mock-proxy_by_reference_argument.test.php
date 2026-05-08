<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Doubles\Mock;
use Bootgly\ACI\Tests\Suite\Test\Specification;


interface MockTarget_4_2_1
{
   public function touch (string &$value): string;
}


return new Specification(
   description: 'Mock Proxy — preserves by-reference arguments',

   test: new Assertions(Case: function (): Generator {
      $Target = new Mock(MockTarget_4_2_1::class);
      $Target->stub('touch', function (string &$value): string {
         $value = "changed:{$value}";

         return $value;
      });

      $value = 'start';
      $returned = $Target->Proxy->touch($value);

      yield (new Assertion(description: 'stub receives original variable by reference'))
         ->expect($value)
         ->to->be('changed:start')
         ->assert();

      yield (new Assertion(description: 'proxy returns closure result'))
         ->expect($returned)
         ->to->be('changed:start')
         ->assert();
   })
);
