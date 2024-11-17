<?php

use Generator;
use StdClass;

use Bootgly\ACI\Tests\Cases\Assertion;
use Bootgly\ACI\Tests\Cases\Assertions;

return [
   // @ configure
   'separator.line' => 'Advanced API',
   'describe' => 'It should handle API',
   'skip' => true,
   // @ simulate
   // ...
   // @ test
   'test' => new Assertions(Case: function (): Generator
   {
      try {
         $Assertion = new Assertion(
            description: 'Cannot access private `asserted` property',
            fallback: 'Private property `asserted` accessed!'
         );
         $Assertion->asserted = false;

         yield $Assertion->assert(
            actual: true,
            expected: false
         );
      }
      catch (Throwable $Throwable) {
         yield $Assertion->assert(
            actual: $Throwable->getMessage(),
            expected: 'Cannot modify private(set) property Bootgly\ACI\Tests\Cases\Assertion::$asserted from scope Bootgly\ACI\Tests\Cases\Assertions'
         );
      }
   })
];
