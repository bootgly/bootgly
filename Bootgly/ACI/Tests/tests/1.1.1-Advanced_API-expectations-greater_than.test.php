<?php

use Generator;
use stdClass;

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
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
         // 2 > 1
         ->expect(2, Op::GreaterThan, 1)
         ->assert();

      // float
      yield new Assertion(
         description: 'Greater than [float]',
         fallback: 'Floats not matched!'
      )
         // 3.00 > 1.1
         ->expect(3.00, Op::GreaterThan, 1.1)
         ->assert();

      // string
      yield new Assertion(
         description: 'Greater than [strings]',
         fallback: 'Strings not matched!'
      )
         // 'Bootgly!' > 'Bootgly'
         ->expect('Bootgly!', Op::GreaterThan, 'Bootgly')
         ->assert();

      // array
      yield new Assertion(
         description: 'Greater than [arrays]',
         fallback: 'Arrays not matched!'
      )
         ->expect([1, 2, 3, 4], Op::GreaterThan, [1, 2, 3])
         ->assert();

      // object
      $object1 = new stdClass();
      $object1->property = 'value';
      $object2 = new stdClass();

      yield new Assertion(
         description: 'Greater than [objects]',
         fallback: 'Objects not matched!'
      )
         ->expect($object1, Op::GreaterThan, $object2)
         ->assert();
   })
];
