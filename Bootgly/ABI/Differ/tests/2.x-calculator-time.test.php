<?php

use Bootgly\ABI\Differ\Calculators\Time;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Calculators\Time: LCS table-based',
   test: function () {
      $Calc = new Time;

      yield assert(
         assertion: $Calc->calculate(['a', 'b', 'c'], ['a', 'b', 'c']) === ['a', 'b', 'c'],
         description: 'identical sequences'
      );

      yield assert(
         assertion: $Calc->calculate([], ['a']) === [],
         description: 'empty from'
      );

      yield assert(
         assertion: $Calc->calculate(['a', 'x', 'c'], ['a', 'y', 'c']) === ['a', 'c'],
         description: 'middle replacement'
      );

      yield assert(
         assertion: $Calc->calculate(['a', 'b', 'c', 'd'], ['b', 'd']) === ['b', 'd'],
         description: 'subsequence preserved'
      );
   }
);
