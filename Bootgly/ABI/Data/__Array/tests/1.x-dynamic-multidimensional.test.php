<?php

use Bootgly\ABI\Data\__Array;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: '',
   test: function () {
      // Valid
      $__Array = new __Array([
         'a' => ['b' => 'c']
      ]);
      yield assert(
         assertion: $__Array->multidimensional === true,
         description: 'Array is multidimensional'
      );
      // Invalid
      $__Array = new __Array(['a', 'b', 'c']);
      yield assert(
         assertion: $__Array->multidimensional === false,
         description: 'Array is not multidimensional'
      );
   }
);
