<?php

use Generator;
use stdClass;

use Bootgly\ACI\Tests\Assertion\Comparators;
use Bootgly\ACI\Tests\Cases\Assertion;
use Bootgly\ACI\Tests\Cases\Assertions;

return [
   // @ configure
   'describe' => 'It should compare less than',
   // @ simulate
   // ...
   // @ test
   'test' => new Assertions(Case: function (): Generator
   {
      // boolean
      yield new Assertion(
         description: 'Less than [boolean]',
         fallback: 'Booleans not matched!'
      )
         ->assert(
            actual: false,
            expected: true
         );

      // integer
      yield new Assertion(
         description: 'Less than [int]',
         fallback: 'Integers not matched!'
      )
         ->assert(
            actual: 1,
            expected: 2
         );

      // float
      yield new Assertion(
         description: 'Less than [float]',
         fallback: 'Floats not matched!'
      )
         ->assert(
            actual: 1.1,
            expected: 2.1
         );

      // string
      yield new Assertion(
         description: 'Less than [strings]',
         fallback: 'Strings not matched!'
      )
         ->assert(
            actual: 'Bootgly',
            expected: 'Bootgly!',
         );

      // array
      yield new Assertion(
         description: 'Less than [arrays]',
         fallback: 'Arrays not matched!'
      )
         ->assert(
            actual: [1, 2, 3],
            expected: [1, 2, 3, 4],
         );

      // object
      $object1 = new stdClass();
      $object2 = new stdClass();
      $object2->property = 'value';
      yield new Assertion(
         description: 'Less than [objects]',
         fallback: 'Objects not matched!'
      )
         ->assert(
            actual: $object1,
            expected: $object2,
         );
   })
      ->assertAll(With: new Comparators\LessThan),
];
