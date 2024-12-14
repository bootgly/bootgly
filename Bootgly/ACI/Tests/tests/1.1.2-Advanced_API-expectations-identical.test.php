<?php

use Generator;
use stdClass;

use Bootgly\ACI\Tests\Cases\Assertion;
use Bootgly\ACI\Tests\Cases\Assertions;
use Bootgly\ACI\Tests\Assertions\Hook;

return [
   // @ configure
   'describe' => 'It should compare equal values',
   // @ simulate
   // ...
   // @ test
   'test' => new Assertions(Case: function (): Generator
   {
      // boolean
      yield new Assertion(
         description: 'Equal booleans',
         fallback: 'Booleans not matched!'
      )
         ->expect(true)
         ->to->be(true)
         ->assert();

      // integer
      yield new Assertion(
         description: 'Equal integers',
         fallback: 'Integers not matched!'
      )
         ->expect(1)
         ->to->be(1)
         ->assert();

      // float
      yield new Assertion(
         description: 'Equal floats',
         fallback: 'Floats not matched!'
      )
         ->expect(1.1)
         ->to->be(1.1)
         ->assert();

      // string
      yield new Assertion(
         description: 'Equal strings',
         fallback: 'Strings not matched!'
      )
         ->expect('Bootgly')
         ->to->be('Bootgly')
         ->assert();

      // array
      yield new Assertion(
         description: 'Equal arrays',
         fallback: 'Arrays not matched!'
      )
         ->expect([1, 2, 3])
         ->to->be([1, 2, 3])
         ->assert();

      // object
      $object1 = new stdClass();

      yield new Assertion(
         description: 'Equal objects',
         fallback: 'Objects not matched!'
      )
         ->expect($object1)
         ->to->be($object1)
         ->assert();
   })
      ->input('test')
      ->on(Hook::BeforeEach, function ($Assertion, $arguments): void
      {
         // do anything before each assertion
      })
];
