<?php

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Type;
use Generator;

use Bootgly\ACI\Tests\Assertion\Expectations\Comparators\Identical;
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
      yield new Assertion(
         description: 'Asserts true (implicit)',
      )
         ->expect(true)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'Asserts true (explicit)',
      )
         ->expect(actual: true)
         ->to->be(expected: new Identical(true))
         ->assert();
   })
];
