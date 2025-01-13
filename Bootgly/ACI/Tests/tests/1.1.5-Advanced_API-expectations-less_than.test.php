<?php

use Generator;
use stdClass;

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;

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
         // false < true
         ->expect(false, Op::LessThan, true)
         ->assert();

      // integer
      yield new Assertion(
         description: 'Less than [int]',
         fallback: 'Integers not matched!'
      )
         // 1 < 2
         ->expect(1, Op::LessThan, 2)
         ->assert();

      // float
      yield new Assertion(
         description: 'Less than [float]',
         fallback: 'Floats not matched!'
      )
         // 1.1 < 2.1
         ->expect(1.1, Op::LessThan, 2.1)
         ->assert();

      // string
      yield new Assertion(
         description: 'Less than [strings]',
         fallback: 'Strings not matched!'
      )
         // 'Bootgly' < 'Bootgly!'
         ->expect('Bootgly', Op::LessThan, 'Bootgly!')
         ->assert();

      // array
      yield new Assertion(
         description: 'Less than [arrays]',
         fallback: 'Arrays not matched!'
      )
         // [1, 2, 3] < [1, 2, 3, 4]
         ->expect([1, 2, 3], Op::LessThan, [1, 2, 3, 4])
         ->assert();

      // object
      $object1 = new stdClass();
      $object2 = new stdClass();
      $object2->property = 'value';
      yield new Assertion(
         description: 'Less than [objects]',
         fallback: 'Objects not matched!'
      )
         // $object1 < $object2
         ->expect($object1, Op::LessThan, $object2)
         ->assert();
   })
];
