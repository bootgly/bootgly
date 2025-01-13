
<?php

use Generator;
use stdClass;

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;

return [
   // @ configure
   'describe' => 'It should compare greater than or equal',
   // @ simulate
   // ...
   // @ test
   'test' => new Assertions(Case: function (): Generator
   {
      // integer
      yield new Assertion(
         description: 'Greater than or equal [int]',
         fallback: 'Integers not matched!'
      )
         // 2 >= 1
         ->expect(2, Op::GreaterThanOrEqual, 1)
         ->assert();

      // float
      yield new Assertion(
         description: 'Greater than or equal [float]',
         fallback: 'Floats not matched!'
      )
         // 3.00 >= 1.1
         ->expect(3.00, Op::GreaterThanOrEqual, 1.1)
         ->assert();

      // string
      yield new Assertion(
         description: 'Greater than or equal [strings]',
         fallback: 'Strings not matched!'
      )
         // 'Bootgly!' >= 'Bootgly'
         ->expect('Bootgly!', Op::GreaterThanOrEqual, 'Bootgly')
         ->assert();

      // array
      yield new Assertion(
         description: 'Greater than or equal [arrays]',
         fallback: 'Arrays not matched!'
      )
         ->expect([1, 2, 3, 4], Op::GreaterThanOrEqual, [1, 2, 3])
         ->assert();

      // object
      $object1 = new stdClass();
      $object1->property = 'value';
      $object2 = new stdClass();

      yield new Assertion(
         description: 'Greater than or equal [objects]',
         fallback: 'Objects not matched!'
      )
         ->expect($object1, Op::GreaterThanOrEqual, $object2)
         ->assert();
   })
];