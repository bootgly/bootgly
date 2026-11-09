<?php


use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should compare equality',
   test: new Assertions(Case: function (): Generator
   {
      // integer
      yield new Assertion(
         description: 'Equal [int]',
         fallback: 'Integers not matched!'
      )
         // 1 == 1
         ->expect(1, Op::Equal, 1)
         ->assert();

      // float
      yield new Assertion(
         description: 'Equal [float]',
         fallback: 'Floats not matched!'
      )
         // 1.1 == 1.1
         ->expect(1.1, Op::Equal, 1.1)
         ->assert();

      // string
      yield new Assertion(
         description: 'Equal [strings]',
         fallback: 'Strings not matched!'
      )
         // 'Bootgly' == 'Bootgly'
         ->expect('Bootgly', Op::Equal, 'Bootgly')
         ->assert();

      // array
      yield new Assertion(
         description: 'Equal [arrays]',
         fallback: 'Arrays not matched!'
      )
         ->expect([1, 2, 3], Op::Equal, [1, 2, 3])
         ->assert();

      // object
      $Object1 = new stdClass();
      $Object1->property = 'value';
      $Object2 = new stdClass();
      $Object2->property = 'value';

      yield new Assertion(
         description: 'Equal [objects]',
         fallback: 'Objects not matched!'
      )
         ->expect($Object1, Op::Equal, $Object2)
         ->assert();
   })
);
