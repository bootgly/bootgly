<?php

use Generator;
use StdClass;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;


return new Specification(
   Separator: new Separator(line: 'Advanced API'),
   description: 'It should handle API',
   test: new Assertions(Case: function (): Generator
   {
      $Assertion = new Assertion(
         description: 'Cannot access private `asserted` property',
         fallback: 'Private property `asserted` accessed!'
      );

      try {
         $Assertion->asserted = false;

         yield $Assertion->assert(
            actual: true,
            expected: false
         );
      }
      catch (Throwable $Throwable) {
         yield $Assertion->assert(
            actual: $Throwable->getMessage(),
            expected: 'Cannot modify private(set) property Bootgly\ACI\Tests\Assertion::$asserted from scope Bootgly\ACI\Tests\Assertions'
         );
      }
   })
);
