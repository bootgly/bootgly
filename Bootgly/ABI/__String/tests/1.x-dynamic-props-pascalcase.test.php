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
      // ASCII text
      $ASCII = new __String('Bootgly is efficient!', 'ASCII');
      assert(
         assertion: $ASCII->pascalcase === 'Bootgly Is Efficient!',
         description: 'ASCII text converted to pascalcase: ' . $ASCII->pascalcase
      );
      // UTF-8 text
      $ASCII = new __String('Bootgly é eficiente!');
      assert(
         assertion: $ASCII->pascalcase === 'Bootgly É Eficiente!',
         description: 'UTF-8 text converted to uppercase: ' . $ASCII->pascalcase
      );
      // @ Invalid
      // ...

      return true;
   }
];
