<?php

use Generator;
use stdClass;

use Bootgly\ACI\Tests\Assertion\Comparators;
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
         ->assert(
            actual: true,
            expected: true,
         );

      // integer
      yield new Assertion(
         description: 'Equal integers',
         fallback: 'Integers not matched!'
      )
         ->assert(
            actual: 1,
            expected: 1,
         );

      // float
      yield new Assertion(
         description: 'Equal floats',
         fallback: 'Floats not matched!'
      )
         ->assert(
            actual: 1.1,
            expected: 1.1,
         );

      // string
      yield new Assertion(
         description: 'Equal strings',
         fallback: 'Strings not matched!'
      )
         ->assert(
            actual: 'Bootgly',
            expected: 'Bootgly',
         );

      // array
      yield new Assertion(
         description: 'Equal arrays',
         fallback: 'Arrays not matched!'
      )
         ->assert(
            actual: [1, 2, 3],
            expected: [1, 2, 3],
         );

      // object
      $object1 = new stdClass();

      yield new Assertion(
         description: 'Equal objects',
         fallback: 'Objects not matched!'
      )
         ->assert(
            actual: $object1,
            expected: $object1,
         );
   })
      ->input('test')
      ->on(Hook::BeforeEach, function ($Assertion, $arguments): void
      {
         // do anything before each assertion
      })
      ->assertAll(With: new Comparators\Identical),
];
