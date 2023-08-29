<?php

use Bootgly\ABI\IO\FS\File;


return [
   // @ configure
   'describe' => '',
   'separator.left' => '__get - System',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      $File1 = new File(__DIR__ . '/1.1-construct-real_file.test.php');
      assert(
         assertion: is_int($File1->inode),
         description: 'File #1 - should have inode value!'
      );

      // @ Neutral
      $File2 = new File('');
      assert(
         assertion: $File2->inode === false,
         description: 'File #2 - empty path - inode should be false'
      );

      // @ Invalid
      $File3 = new File(__DIR__ . '/1.1.3-fake.test.php');
      assert(
         assertion: $File3->inode === false,
         description: 'File #3 - fake file - inode should be false'
      );

      return true;
   }
];
