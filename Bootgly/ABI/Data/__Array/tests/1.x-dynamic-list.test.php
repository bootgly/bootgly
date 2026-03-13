<?php

use Bootgly\ABI\Data\__Array;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: '',
   test: function ()
   {
      // Valid
      $__Array = new __Array(['a', 'b', 'c', 'd', 'e', 'f', 'g']);
      yield assert(
         assertion: $__Array->list === true,
         description: 'Array is a list (sequential, numeric)'
      );
      // Invalid
      $__Array = new __Array(['a' => 'b', 'c' => 'e', 'f' => 'g', 'h' => 'i']);
      yield assert(
         assertion: $__Array->list === false,
         description: 'Array is not a list (associative)'
      );
   }
);
