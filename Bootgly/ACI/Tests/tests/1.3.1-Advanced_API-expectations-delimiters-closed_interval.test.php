<?php

use Generator;

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
         ->expect(1)
         ->to->delimit(1, 2)
         ->assert();

      // float
      yield new Assertion(
         description: 'Between floats',
         fallback: 'Floats not matched!'
      )
         ->expect(1.5)
         ->to->delimit(1.5, 2.5)
         ->assert();

      // DateTime
      $date = new DateTime('2023-01-01');
      yield new Assertion(
         description: 'Between DateTime objects',
         fallback: 'DateTime objects not matched!'
      )
         ->expect($date)
         ->to->delimit($date, new DateTime('2023-01-02'))
         ->assert();
   }),
];
