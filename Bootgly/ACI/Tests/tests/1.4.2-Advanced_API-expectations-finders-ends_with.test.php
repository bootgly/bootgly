<?php

use Generator;

use Bootgly\ACI\Tests\Assertion\Expectations\Finders\EndsWith;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should compare using the finder "End"',
   test: new Assertions(Case: function (): Generator
   {
      // string
      yield new Assertion(
         description: 'Ends with string',
         fallback: 'Strings not matched!'
      )
         ->assert(
            actual: 'Hello, World!',
            expected: new EndsWith('World!'),
         );
   })
);
