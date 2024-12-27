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
      // expect (comparing values)
      yield new Assertion(
         description: 'expect (comparing values)',
      )
         ->expect(2, Op::Identical, 2)
         ->assert();

      // to be [true] (implicit)
      yield new Assertion(
         description: 'to be [true] (implicit)',
      )
         ->expect(true)
         ->to->be(true)
         ->assert();

      // to be [true] (explicit)
      yield new Assertion(
         description: 'to be [true] (explicit)',
      )
         ->expect(actual: true)
         ->to->be(expected: new Identical(true))
         ->assert();

      // ---

      // # NOT
      // NOT to be [true]
      yield new Assertion(
         description: 'NOT to be [true]',
      )
         ->expect(true)
         ->not->to->be(false)
         ->assert();

      // # AND
      // to be [true] AND [true]
      yield new Assertion(
         description: 'to be [true] AND [true]',
      )
         ->expect(true)
         ->to->be(true)
         ->and
         ->to->be(true)
         ->assert();

      // # OR
      // to be [false] OR [true]
      yield new Assertion(
         description: 'to be [false] OR [true]',
      )
         ->expect(true)
         ->to->be(false)
         ->or
         ->to->be(true)
         ->assert();

      // to be [true] OR [false]
      yield new Assertion(
         description: 'to be [true] OR [false]',
      )
         ->expect(true)
         ->to->be(true)
         ->or
         ->to->be(false)
         ->assert();

      // to be [true] OR [true]
      yield new Assertion(
         description: 'to be [true] OR [true]',
      )
         ->expect(true)
         ->to->be(true)
         ->or
         ->to->be(true)
         ->assert();
   })
];
