<?php

use Bootgly\ABI\__String;


return [
   // @ configure
   'describe' => '',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      $String1 = new __String('Bootgly is efficient!');

      $Result = $String1->search('is');

      assert(
         assertion: $Result->found === 'is' && $Result->position === 8,
         description: 'Result found / position: ' . $Result->found . ' / ' . $Result->position
      );
      // @ Invalid
      // ...

      return true;
   }
];
