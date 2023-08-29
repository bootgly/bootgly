<?php

use Bootgly\ABI\IO\FS\File;


return [
   // @ configure
   'describe' => '',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      $File1 = new File(__DIR__ . '/1.1-construct-real_file.test.php');
      assert(
         assertion: $File1->extension === 'php',
         description: 'File #1 extension: ' . $File1->extension
      );

      $File2 = new File(__DIR__ . '/1.1.3-fake.test.php');
      assert(
         assertion: $File2->extension === 'php',
         description: 'File #2 (fake) extension: ' . $File2->extension
      );

      // @ Neutral
      $File3 = new File('');
      assert(
         assertion: $File3->extension === '',
         description: 'File #3 extension: ' . $File3->extension
      );

      // @ Invalid
      // ...

      return true;
   }
];
