<?php

use Generator;

use Bootgly\ACI\Tests\Assertion\Expectations\Between;
use Bootgly\ACI\Tests\Cases\Assertion;
use Bootgly\ACI\Tests\Cases\Assertions;

return [
   // @ configure
   'describe' => 'It should compare between values (using Expectations)',
   // @ simulate
   // ...
   // @ test
   'test' => new Assertions(Case: function (): Generator
   {
      // integer
      yield new Assertion(
         description: 'Between integers',
         fallback: 'Integers not matched!'
      )
         ->assert(
            actual: 1,
            expected: new Between(1, 2),
         );

      // float
      yield new Assertion(
         description: 'Between floats',
         fallback: 'Floats not matched!'
      )
         ->assert(
            actual: 1.1,
            expected: new Between(1.1, 2.1),
         );

      // DateTime
      $date = new DateTime('2023-01-01');
      yield new Assertion(
         description: 'Between DateTime objects',
         fallback: 'DateTime objects not matched!'
      )
         ->assert(
            actual: $date,
            expected: new Between(
               new DateTime('2023-01-01'),
               new DateTime('2023-01-02')
            ),
         );
   }),
];
