
<?php

use Generator;
use stdClass;

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Cases\Assertion;
use Bootgly\ACI\Tests\Cases\Assertions;

return [
   // @ configure
   'describe' => 'It should compare less than or equal',
   // @ simulate
   // ...
   // @ test
   'test' => new Assertions(Case: function (): Generator
   {
      // integer
      yield new Assertion(
         description: 'Less than or equal [int]',
         fallback: 'Integers not matched!'
      )
         // 1 <= 2
         ->expect(1, Op::LessThanOrEqual, 2)
         ->assert();

      // float
      yield new Assertion(
         description: 'Less than or equal [float]',
         fallback: 'Floats not matched!'
      )
         // 1.1 <= 3.00
         ->expect(1.1, Op::LessThanOrEqual, 3.00)
         ->assert();

      // string
      yield new Assertion(
         description: 'Less than or equal [strings]',
         fallback: 'Strings not matched!'
      )
         // 'Bootgly' <= 'Bootgly!'
         ->expect('Bootgly', Op::LessThanOrEqual, 'Bootgly!')
         ->assert();

      // array
      yield new Assertion(
         description: 'Less than or equal [arrays]',
         fallback: 'Arrays not matched!'
      )
         ->expect([1, 2, 3], Op::LessThanOrEqual, [1, 2, 3, 4])
         ->assert();

      // object
      $object1 = new stdClass();
      $object2 = new stdClass();
      $object2->property = 'value';

      yield new Assertion(
         description: 'Less than or equal [objects]',
         fallback: 'Objects not matched!'
      )
         ->expect($object1, Op::LessThanOrEqual, $object2)
         ->assert();
   })
];