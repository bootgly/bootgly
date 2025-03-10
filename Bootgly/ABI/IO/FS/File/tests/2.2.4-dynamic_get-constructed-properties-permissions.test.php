<?php

use Bootgly\ABI\IO\FS\File;


return [
   // @ configure
   'describe' => '',
   'separator.left' => '__get - Access',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      $File1 = new File(__DIR__ . '/1.1-construct-real_file.test.php');
      yield assert(
         assertion: $File1->permissions === 33188,
         description: 'File #1 - Permissions: ' . $File1->permissions
      );

      // @ Neutral
      $File2 = new File('');
      yield assert(
         assertion: $File2->permissions === null,
         description: 'File #2 - empty path file - permissions should be null'
      );

      // @ Invalid
      $File3 = new File(__DIR__ . '/1.1.3-fake.test.php');
      yield assert(
         assertion: $File3->permissions === null,
         description: 'File #3 - fake file - permissions should be null'
      );
   }
];
