
<?php

use Generator;
use stdClass;

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;

return [
   // @ configure
   'describe' => 'It should compare not identical',
   // @ simulate
   // ...
   // @ test
   'test' => new Assertions(Case: function (): Generator
   {
      // integer
      yield new Assertion(
         description: 'Not identical [int]',
         fallback: 'Integers not matched!'
      )
         // 1 !== 2
         ->expect(1, Op::NotIdentical, 2)
         ->assert();

      // float
      yield new Assertion(
         description: 'Not identical [float]',
         fallback: 'Floats not matched!'
      )
         // 1.1 !== 3.00
         ->expect(1.1, Op::NotIdentical, 3.00)
         ->assert();

      // string
      yield new Assertion(
         description: 'Not identical [strings]',
         fallback: 'Strings not matched!'
      )
         // 'Bootgly' !== 'Bootgly!'
         ->expect('Bootgly', Op::NotIdentical, 'Bootgly!')
         ->assert();

      // array
      yield new Assertion(
         description: 'Not identical [arrays]',
         fallback: 'Arrays not matched!'
      )
         ->expect([1, 2, 3], Op::NotIdentical, [1, 2, 3, 4])
         ->assert();

      // object
      $object1 = new stdClass();
      $object2 = new stdClass();
      $object2->property = 'value';

      yield new Assertion(
         description: 'Not identical [objects]',
         fallback: 'Objects not matched!'
      )
         ->expect($object1, Op::NotIdentical, $object2)
         ->assert();
   })
];