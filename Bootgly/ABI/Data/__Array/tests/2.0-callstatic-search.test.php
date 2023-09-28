<?php

use Bootgly\ABI\Data\__Array;


return [
   // @ configure
   'describe' => '',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // ...

      $__Array = new __Array(['Bootgly', 'base', 'PHP', 'framework', 'to', 'Multi', 'Projects']);

      $Result = $__Array->search('framework');
      yield assert(
         assertion: $Result->key === 3,
         description: 'Found key is: ' . $Result->key
      );
      yield assert(
         assertion: $Result->value === 'framework',
         description: 'Found value is: ' . $Result->value
      );
      yield assert(
         assertion: $Result->found === true,
         description: 'Result not found!'
      );
   }
];
