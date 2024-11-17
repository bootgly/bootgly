<?php

use Bootgly\ACI\Tests\Cases\Assertion;

return [
   // @ configure
   'describe' => 'It should assert returning string (fallback)',
   // @ test
   'test' => function (bool $expected = false): string|bool
   {
      if ($expected === false) {
         Assertion::$description = 'Asserting that true is false';
         return "This is a fallback message (test failed)!";
      }

      Assertion::$description = 'Asserting that true is true';
      return true;
   },
   'retest' => function (callable $test, bool $passed, mixed ...$arguments): string|bool|null
   {
      if ($passed === false) {
         return $test(true);
      }

      return null;
   }
];
