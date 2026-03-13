<?php
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should assert returning boolean (retestable)',
   test: function (bool $expected = false): bool
   {
      return true === $expected;
   },
   retest: function (callable $test, bool $passed, mixed ...$arguments): string|bool|null
   {
      // ? If the last test failed, retest changing dataset
      if ($passed === false) {
         return $test(true);
      }

      return null;
   }
);
