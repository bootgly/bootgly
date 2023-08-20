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
      assert(
         assertion: $ASCII->lowercase === 'bootgly is efficient!',
         description: 'ASCII text converted to lowercase: ' . $ASCII->lowercase
      );
      // UTF-8 text
      $ASCII = new __String('Bootgly é eficiente!');
      assert(
         assertion: $ASCII->lowercase === 'bootgly é eficiente!',
         description: 'UTF-8 text converted to lowercase: ' . $ASCII->lowercase
      );
      // @ Invalid
      // ...

      return true;
   }
];
