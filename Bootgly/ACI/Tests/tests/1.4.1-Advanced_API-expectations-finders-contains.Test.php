<?php


use Bootgly\ACI\Tests\Assertion\Expectations\Finders\Contains;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
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
      $Object = new stdClass();
      $Object->property = 'Hello, World!';
      yield new Assertion(
         description: 'Contains object property',
         fallback: 'Objects not matched!'
      )
         ->assert(
            actual: $Object,
            expected: new Contains('property'),
         );
   })
);
