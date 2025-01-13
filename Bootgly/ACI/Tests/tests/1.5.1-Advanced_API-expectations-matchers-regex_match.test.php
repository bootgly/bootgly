<?php

use Generator;

use Bootgly\ACI\Tests\Assertion\Expectations\Matchers\Regex;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;

return [
   // @ configure
   'describe' => 'It should compare using the matcher "RegexMatch"',
   // @ simulate
   // ...
   // @ test
   'test' => new Assertions(Case: function (): Generator
   {
      // string
      yield new Assertion(
         description: 'Matches string',
         fallback: 'Strings not matched!'
      )
         ->assert(
            actual: 'Hello, World!',
            expected: new Regex('/World/'),
         );
   }),
];
