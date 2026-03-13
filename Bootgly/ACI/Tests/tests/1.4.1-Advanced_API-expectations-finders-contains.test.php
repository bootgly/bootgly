<?php

use Generator;

use Bootgly\ACI\Tests\Assertion\Expectations\Finders\Contains;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should compare using the finder "Contain"',
   test: new Assertions(Case: function (): Generator
   {
      // string
      yield new Assertion(
         description: 'Contains string',
         fallback: 'Strings not matched!'
      )
         ->assert(
            actual: 'Hello, World!',
            expected: new Contains('World'),
         );

      // array
      yield new Assertion(
         description: 'Contains array',
         fallback: 'Arrays not matched!'
      )
         ->assert(
            actual: ['Hello', 'World!'],
            expected: new Contains('World!'),
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
            expected: new Contains('property'),
         );
   })
);
