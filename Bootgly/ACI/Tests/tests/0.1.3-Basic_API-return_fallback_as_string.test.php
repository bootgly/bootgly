<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should assert returning string (fallback)',
   test: function (bool $expected = false): string|bool
   {
      if ($expected === false) {
         Assertion::$description = 'Asserting that true is false';
         return "This is a fallback message (test failed)!";
      }

      Assertion::$description = 'Asserting that true is true';
      return true;
   },
   retest: function (callable $test, bool $passed, mixed ...$arguments): string|bool|null
   {
      if ($passed === false) {
         return $test(true);
      }

      return null;
   }
);
