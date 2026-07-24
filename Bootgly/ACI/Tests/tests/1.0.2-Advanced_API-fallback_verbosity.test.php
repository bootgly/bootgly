<?php

use Generator;

use function str_contains;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Comparators\NotEqual;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Fallback verbosity: -v raises the detail of assertion failures',
   test: new Assertions(Case: function (): Generator
   {
      // # The runner feeds this from the CLI -v/-vv/-vvv global option
      yield new Assertion(
         description: 'Assertion::$verbosity defaults to 0'
      )
         ->assert(
            actual: Assertion::$verbosity,
            expected: 0
         );

      $NotEqual = new NotEqual();

      // # verbosity 0 → generic placeholders, real values redacted
      $terse = (string) $NotEqual->fail(5, 5, 0);
      yield new Assertion(
         description: 'verbosity 0 renders the placeholder words'
      )
         ->assert(
            actual: str_contains($terse, 'actual') && str_contains($terse, 'expected'),
            expected: true
         );
      yield new Assertion(
         description: 'verbosity 0 hides the real values'
      )
         ->assert(
            actual: str_contains($terse, '5 is not equal to 5'),
            expected: false
         );

      // # verbosity 1 → the real scalar values are shown
      $verbose = (string) $NotEqual->fail(5, 5, 1);
      yield new Assertion(
         description: 'verbosity 1 renders the real values'
      )
         ->assert(
            actual: str_contains($verbose, '5 is not equal to 5'),
            expected: true
         );

      // # verbosity 1 → arrays are JSON-encoded into the message
      $array = (string) $NotEqual->fail([1, 2], [3, 4], 1);
      yield new Assertion(
         description: 'verbosity 1 JSON-encodes array values'
      )
         ->assert(
            actual: str_contains($array, '[1,2]') && str_contains($array, '[3,4]'),
            expected: true
         );
   })
);
