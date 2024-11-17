<?php

use Generator;

use Bootgly\ACI\Tests\Assertion\Finders;
use Bootgly\ACI\Tests\Cases\Assertion;
use Bootgly\ACI\Tests\Cases\Assertions;

return [
   // @ configure
   'describe' => 'It should compare using the finder "Contains"',
   // @ simulate
   // ...
   // @ test
   'test' => new Assertions(Case: function (): Generator
   {
      // string
      yield new Assertion(
         description: 'Contains string',
         fallback: 'Strings not matched!'
      )
         ->assert(
            actual: 'Hello, World!',
            expected: new Finders\Contains('World'),
         );

      // array
      yield new Assertion(
         description: 'Contains array',
         fallback: 'Arrays not matched!'
      )
         ->assert(
            actual: ['Hello', 'World!'],
            expected: new Finders\Contains('World!'),
         );

      // object
      $object = new stdClass();
      $object->property = 'Hello, World!';
      yield new Assertion(
         description: 'Contains object property',
         fallback: 'Objects not matched!'
      )
         ->assert(
            actual: $object,
            expected: new Finders\Contains('property'),
         );
   }),
];
