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
      // ASCII text
      $ASCII = new __String('Bootgly is efficient!', 'ASCII');
      yield assert(
         assertion: $ASCII->uppercase === 'BOOTGLY IS EFFICIENT!',
         description: 'ASCII text converted to uppercase: ' . $ASCII->uppercase
      );
      // UTF-8 text
      $ASCII = new __String('Bootgly é eficiente!');
      yield assert(
         assertion: $ASCII->uppercase === 'BOOTGLY É EFICIENTE!',
         description: 'UTF-8 text converted to uppercase: ' . $ASCII->uppercase
      );
      // @ Invalid
      // ...
   }
];
