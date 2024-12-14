<?php

use Generator;

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion\Comparators\Identical;
use Bootgly\ACI\Tests\Cases\Assertion;
use Bootgly\ACI\Tests\Cases\Assertions;

return [
   // @ configure
   'describe' => 'It should use assert API',
   // @ simulate
   // ...
   // @ test
   'test' => new Assertions(Case: function (): Generator
   {
      // expect comparing values (explicit identical)
      yield new Assertion(
         description: 'expect comparing values (explicit identical)',
      )
         ->expect(2, Op::Identical, 2)
         ->assert();

      // be [true] (implicit)
      yield new Assertion(
         description: 'to be [true] (implicit)',
      )
         ->expect(true)
         ->to->be(true)
         ->assert();

      // be [true] (explicit)
      yield new Assertion(
         description: 'to be [true] (explicit)',
      )
         ->expect(actual: true)
         ->to->be(expected: new Identical(true))
         ->assert();
   })
];
