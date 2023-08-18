<?php

use Bootgly\ABI\data\Dir;


return [
   // @ configure
   'describe' => '',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      // writable
      $Dir1 = new Dir;
      // * Config
      $Dir1->real = true;
      // @
      $Dir1->construct(__DIR__);
      assert(
         assertion: $Dir1->writable === true,
         description: 'Current directory is writable!'
      );

      // non-writable
      $Dir2 = new Dir;
      // * Config
      $Dir2->real = true;
      // @
      $Dir2->construct('/sbin');
      assert(
         assertion: $Dir2->writable === false,
         description: '/sbin directory is non-writable!'
      );      

      return true;
   }
];
