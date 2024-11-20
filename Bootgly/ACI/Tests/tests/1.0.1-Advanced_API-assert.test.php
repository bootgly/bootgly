<?php

use Generator;

use const Bootgly\ACI\Tests\Assertion\Comparators\Identical;
use Bootgly\ACI\Tests\Cases\Assertion;
use Bootgly\ACI\Tests\Cases\Assertions;

return [
   // @ configure
   'describe' => 'It should use assert API',
   // @ simulate
   // ...
   // @ test
   'test' => new Assertions(Case: function (): Generator
   {
      yield new Assertion(
         description: 'Asserts true',
         fallback: 'True not asserted!'
      )
         ->assert(
            actual: true, 
            expected: true,
            With: Identical
         );
   })
];
