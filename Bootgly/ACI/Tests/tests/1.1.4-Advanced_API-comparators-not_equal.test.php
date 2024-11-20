<?php

use Generator;
use stdClass;

use Bootgly\ACI\Tests\Assertion\Comparators;
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
            With: new Comparators\NotEqual
         );

      // integer
      yield new Assertion(
         description: 'Not equal integers',
         fallback: 'Integers matched!'
      )
         ->assert(
            actual: 1,
            expected: 2,
            With: new Comparators\NotEqual
         );

      // float
      yield new Assertion(
         description: 'Not equal floats',
         fallback: 'Floats matched!'
      )
         ->assert(
            actual: 1.1,
            expected: 2.1,
            With: new Comparators\NotEqual
         );

      // string
      yield new Assertion(
         description: 'Not equal strings',
         fallback: 'Strings matched!'
      )
         ->assert(
            actual: 'Bootgly',
            expected: 'Bootgly!',
            With: new Comparators\NotEqual
         );

      // array
      yield new Assertion(
         description: 'Not equal arrays',
         fallback: 'Arrays matched!'
      )
         ->assert(
            actual: [1, 2, 3],
            expected: [1, 2, 3, 4],
            With: new Comparators\NotEqual
         );

      // object
      $object1 = new stdClass();
      $object2 = new stdClass();
      yield new Assertion(
         description: 'Not equal objects',
         fallback: 'Objects matched!'
      )
         ->assert(
            actual: $object1,
            expected: $object2,
            With: new Comparators\NotEqual
         );
   })
];
