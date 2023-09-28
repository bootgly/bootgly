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
      $Dir1 = new Dir(__DIR__);
      yield assert(
         assertion: $Dir1->permissions === 0755 || $Dir1->permissions === 0750,
         description: 'Current directory permissions (get): ' . $Dir1->permissions
      );

      // @ Invalid
      $Dir2 = new Dir('/usr/sbin');
      yield assert(
         assertion: $Dir2->permissions === 0755,
         description: 'The /usr/sbin directory cannot have modified permissions!'
      );      
   }
];
