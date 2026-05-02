<?php

use Bootgly\ABI\Differ\Calculators\Memory;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Calculators\Memory: LCS Hirschberg-style',
   test: function () {
      $Calc = new Memory;

      yield assert(
         assertion: $Calc->calculate(['a', 'b', 'c'], ['a', 'b', 'c']) === ['a', 'b', 'c'],
         description: 'identical sequences'
      );

      yield assert(
         assertion: $Calc->calculate([], ['a']) === [],
         description: 'empty from'
      );

      yield assert(
         assertion: $Calc->calculate(['x'], ['a', 'x', 'b']) === ['x'],
         description: 'single element found'
      );

      yield assert(
         assertion: $Calc->calculate(['a', 'b', 'c', 'd'], ['b', 'd']) === ['b', 'd'],
         description: 'subsequence preserved'
      );
   }
);
