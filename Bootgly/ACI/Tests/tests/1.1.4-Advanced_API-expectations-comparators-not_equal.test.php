<?php

use Generator;
use stdClass;

use Bootgly\ACI\Tests\Assertion\Expectations\Comparators\NotEqual;
use Bootgly\ACI\Tests\Cases\Assertion;
use Bootgly\ACI\Tests\Cases\Assertions;

return [
   // @ configure
   'describe' => 'It should compare not equal',
   // @ simulate
   // ...
   // @ test
   'test' => new Assertions(Case: function (): Generator
   {
      // boolean
      yield new Assertion(
         description: 'Not equal booleans',
         fallback: 'Booleans matched!'
      )
         ->assert(
            actual: true,
            expected: false,
            using: new NotEqual
         );

      // integer
      yield new Assertion(
         description: 'Not equal integers',
         fallback: 'Integers matched!'
      )
         ->assert(
            actual: 1,
            expected: 2,
            using: new NotEqual
         );

      // float
      yield new Assertion(
         description: 'Not equal floats',
         fallback: 'Floats matched!'
      )
         ->assert(
            actual: 1.1,
            expected: 2.1,
            using: new NotEqual
         );

      // string
      yield new Assertion(
         description: 'Not equal strings',
         fallback: 'Strings matched!'
      )
         ->assert(
            actual: 'Bootgly',
            expected: 'Bootgly!',
            using: new NotEqual
         );

      // array
      yield new Assertion(
         description: 'Not equal arrays',
         fallback: 'Arrays matched!'
      )
         ->assert(
            actual: [1, 2, 3],
            expected: [1, 2, 3, 4],
            using: new NotEqual
         );

      // object
      $object1 = new stdClass();
      $object2 = new stdClass();
      $object2->property = 'value';
      yield new Assertion(
         description: 'Not equal objects',
         fallback: 'Objects matched!'
      )
         ->assert(
            actual: $object1,
            expected: $object2,
            using: new NotEqual
         );
   })
];
