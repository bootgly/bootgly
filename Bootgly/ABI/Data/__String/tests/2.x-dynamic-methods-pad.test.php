<?php

use Bootgly\ABI\Data\__String;


return [
   // @ configure
   'describe' => '',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      $String1 = new __String('Bootgly é eficiente!');

      $result = $String1->pad(22, '_', STR_PAD_BOTH);

      assert(
         assertion: $result === '_Bootgly é eficiente!_',
         description: 'String1 with pad: ' . $result
      );
      // @ Invalid
      // ...

      return true;
   }
];
