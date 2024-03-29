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
];
