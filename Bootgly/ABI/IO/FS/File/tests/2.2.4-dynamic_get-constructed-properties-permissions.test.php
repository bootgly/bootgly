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
      assert(
         assertion: $File1->permissions === 33188,
         description: 'File #1 - Permissions: ' . $File1->permissions
      );

      // @ Neutral
      $File2 = new File('');
      assert(
         assertion: $File2->permissions === false,
         description: 'File #2 - empty path file - permissions should be false'
      );

      // @ Invalid
      $File3 = new File(__DIR__ . '/1.1.3-fake.test.php');
      assert(
         assertion: $File3->permissions === false,
         description: 'File #3 - fake file - permissions should be false'
      );

      return true;
   }
];