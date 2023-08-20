<?php

use Bootgly\ABI\Data\__Array;


return [
   // @ configure
   'describe' => '',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // Valid
      $__Array = new __Array(['a', 'b', 'c', 'd', 'e', 'f', 'g']);
      assert(
         assertion: $__Array->list === true,
         description: 'Array is a list (sequential, numeric)'
      );
      // Invalid
      $__Array = new __Array(['a', 'b', 'c' => 'e', 'f', 'g', 'h', 'i']);
      assert(
         assertion: $__Array->list === false,
         description: 'Array is not a list (associative)'
      );

      return true;
   }
];
