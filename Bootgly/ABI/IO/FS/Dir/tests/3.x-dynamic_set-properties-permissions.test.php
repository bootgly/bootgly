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
      $Dir1->permissions = 0750;
      yield assert(
         assertion: $Dir1->permissions === 0750,
         description: 'Current directory permissions (set): ' . $Dir1->permissions
      );
      $Dir1->permissions = 0755;

      // @ Invalid
      $Dir2 = new Dir('/usr/sbin');
      $Dir2->permissions = 0750;
      yield assert(
         assertion: $Dir2->permissions === false,
         description: <<<EOT
         The /usr/sbin directory cannot have modified permissions!
         EOT
      );
   }
];
