<?php

use Bootgly\ABI\IO\FS\Dir;


return [
   // @ configure
   'describe' => '',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      // writable
      $Dir1 = new Dir(__DIR__);
      yield assert(
         assertion: $Dir1->writable === true,
         description: 'Current directory is writable!'
      );

      // non-writable
      $Dir2 = new Dir('/sbin');
      yield assert(
         assertion: $Dir2->writable === false,
         description: '/sbin directory is non-writable!'
      );      
   }
];
