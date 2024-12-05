<?php

use Generator;
use stdClass;

use Bootgly\ACI\Tests\Assertion\Expectations\Comparators\GreaterThan;
use Bootgly\ACI\Tests\Cases\Assertion;
use Bootgly\ACI\Tests\Cases\Assertions;

return [
   // @ configure
   'separator.line' => 'Advanced API',
   'describe' => 'It should compare greater than',
   // @ simulate
   // ...
   // @ test
   'test' => new Assertions(Case: function (): Generator
   {
      // integer
      yield new Assertion(
         description: 'Greater than [int]',
         fallback: 'Integers not matched!'
      )
         ->assert(
            actual: 2,
            using: new GreaterThan,
            expected: 1,
         );

      // float
      yield new Assertion(
         description: 'Greater than [float]',
         fallback: 'Floats not matched!'
      )
         ->assert(
            actual: 3.00,
            using: new GreaterThan,
            expected: 1.1,
         );

      // string
      yield new Assertion(
         description: 'Greater than [strings]',
         fallback: 'Strings not matched!'
      )
         ->assert(
            actual: 'Bootgly!',
            using: new GreaterThan,
            expected: 'Bootgly',
         );

      // array
      yield new Assertion(
         description: 'Greater than [arrays]',
         fallback: 'Arrays not matched!'
      )
         ->assert(
            actual: [1, 2, 3, 4],
            using: new GreaterThan,
            expected: [1, 2, 3],
         );

      // object
      $object1 = new stdClass();
      $object1->property = 'value';
      $object2 = new stdClass();

      yield new Assertion(
         description: 'Greater than [objects]',
         fallback: 'Objects not matched!'
      )
         ->assert(
            actual: $object1,
            using: new GreaterThan,
            expected: $object2,
         );
   })
];
