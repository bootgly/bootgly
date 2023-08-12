<?php

use Bootgly\ABI\__Array;


return [
   // @ configure
   'describe' => '',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // Valid
      $__Array = new __Array([
         'a' => ['b' => 'c']
      ]);
      assert(
         assertion: $__Array->multidimensional === true,
         description: 'Array is multidimensional'
      );
      // Invalid
      $__Array = new __Array(['a', 'b', 'c']);
      assert(
         assertion: $__Array->multidimensional === false,
         description: 'Array is not multidimensional'
      );

      return true;
   }
];
