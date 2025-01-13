<?php

use Generator;

use Bootgly\ACI\Tests\Assertion\Expectations\Finders\StartsWith;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;

return [
   // @ configure
   'describe' => 'It should compare using the finder "Start"',
   // @ simulate
   // ...
   // @ test
   'test' => new Assertions(Case: function (): Generator
   {
      // string
      yield new Assertion(
         description: 'Starts with string',
         fallback: 'Strings not matched!'
      )
         ->assert(
            actual: 'Hello, World!',
            expected: new StartsWith('Hello'),
         );
   }),
];
