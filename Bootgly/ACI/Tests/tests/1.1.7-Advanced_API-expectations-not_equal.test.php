<?php

use Generator;
use stdClass;

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;

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
         ->expect(true, Op::NotEqual, false)
         ->assert();

      // integer
      yield new Assertion(
         description: 'Not equal integers',
         fallback: 'Integers matched!'
      )
         ->expect(1, Op::NotEqual, 2)
         ->assert();

      // float
      yield new Assertion(
         description: 'Not equal floats',
         fallback: 'Floats matched!'
      )
         ->expect(1.1, Op::NotEqual, 2.1)
         ->assert();

      // string
      yield new Assertion(
         description: 'Not equal strings',
         fallback: 'Strings matched!'
      )
         ->expect('Bootgly', Op::NotEqual, 'Bootgly!')
         ->assert();

      // array
      yield new Assertion(
         description: 'Not equal arrays',
         fallback: 'Arrays matched!'
      )
         ->expect([1, 2, 3], Op::NotEqual, [1, 2, 3, 4])
         ->assert();

      // object
      $object1 = new stdClass();
      $object2 = new stdClass();
      $object2->property = 'value';
      yield new Assertion(
         description: 'Not equal objects',
         fallback: 'Objects matched!'
      )
         ->expect($object1, Op::NotEqual, $object2)
         ->assert();
   })
];
