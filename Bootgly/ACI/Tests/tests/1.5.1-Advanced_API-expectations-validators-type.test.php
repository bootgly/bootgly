<?php

use Generator;

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Type;
use Bootgly\ACI\Tests\Cases\Assertion;
use Bootgly\ACI\Tests\Cases\Assertions;

return [
   // @ configure
   'describe' => 'It should use type validation',
   // @ simulate
   // ...
   // @ test
   'test' => new Assertions(Case: function (): Generator
   {
      /** Test all cases:
      enum Type
      {
         case Array;     // OK
         case Boolean;   // OK
         case Callable;  // OK
         case Countable; // OK
         case Float;     // OK
         case Integer;   // OK
         case Iterable;  // OK
         case Null;      // OK
         case Number;    // OK
         case Numeric;   // OK
         case Object;    // OK
         case Resource;  // OK
         case Scalar;    // OK
         case String;    // OK
      }
      */

      // array
      yield new Assertion(
         description: 'Validating array',
      )
         ->expect([])
         ->to->validate(Type::Array)
         ->assert();

      // boolean
      yield new Assertion(
         description: 'Validating boolean',
      )
         ->expect(true)
         ->to->validate(Type::Boolean)
         ->assert();

      // callable
      yield new Assertion(
         description: 'Validating callable',
      )
         ->expect(function() {})
         ->to->validate(Type::Callable)
         ->assert();

      // countable
      yield new Assertion(
         description: 'Validating countable',
      )
         ->expect(new ArrayObject())
         ->to->validate(Type::Countable)
         ->assert();

      // float
      yield new Assertion(
         description: 'Validating float',
      )
         ->expect(1.0)
         ->to->validate(Type::Float)
         ->assert();

      // integer
      yield new Assertion(
         description: 'Validating integer',
      )
         ->expect(1)
         ->to->validate(Type::Integer)
         ->assert();

      // iterable
      yield new Assertion(
         description: 'Validating iterable',
      )
         ->expect([])
         ->to->validate(Type::Iterable)
         ->assert();

      // null
      yield new Assertion(
         description: 'Validating null',
      )
         ->expect(null)
         ->to->validate(Type::Null)
         ->assert();

      // number
      yield new Assertion(
         description: 'Validating number',
      )
         ->expect(1)
         ->to->validate(Type::Number)
         ->assert();

      // numeric
      yield new Assertion(
         description: 'Validating numeric',
      )
         ->expect('1')
         ->to->validate(Type::Numeric)
         ->assert();

      // object
      yield new Assertion(
         description: 'Validating object',
      )
         ->expect(new stdClass())
         ->to->validate(Type::Object)
         ->assert();

      // resource
      $resource = fopen('php://temp', 'r');
      yield new Assertion(
         description: 'Validating resource',
      )
         ->expect($resource)
         ->to->validate(Type::Resource)
         ->assert();
      fclose($resource);

      // scalar
      yield new Assertion(
         description: 'Validating scalar',
      )
         ->expect(1)
         ->to->validate(Type::Scalar)
         ->assert();

      // string
      yield new Assertion(
         description: 'Validating string',
      )
         ->expect('string')
         ->to->validate(Type::String)
         ->assert();
   })
];
