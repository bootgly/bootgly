<?php

use Generator;

use Bootgly\ACI\Tests\Assertion\Finders;
use Bootgly\ACI\Tests\Cases\Assertion;
use Bootgly\ACI\Tests\Cases\Assertions;

return [
   // @ configure
   'describe' => 'It should compare using the finder "EndsWith"',
   // @ simulate
   // ...
   // @ test
   'test' => new Assertions(Case: function (): Generator
   {
      // string
      yield new Assertion(
         description: 'Ends with string',
         fallback: 'Strings not matched!'
      )
         ->assert(
            actual: 'Hello, World!',
            expected: new Finders\EndsWith('World!'),
         );
   })
];
