<?php

use Bootgly\ABI\IO\FS\File;


return [
   // @ configure
   'describe' => '',
   'separator.line' => true,
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      $File1 = new File;
      $File1->construct(__DIR__ . '/1.1-construct-real_file.test.php');

      assert(
         assertion: $File1->basename === '1.1-construct-real_file.test.php',
         description: 'File #1 basename: ' . $File1->basename
      );

      // @ Neutral
      $File2 = new File;
      $File2->construct('');

      assert(
         assertion: $File2->basename === '',
         description: 'File #2 basename: ' . $File2->basename
      );

      // @ Invalid
      $File3 = new File;
      $File3->construct(__DIR__ . '/1.1.3-fake.test.php');

      assert(
         assertion: $File3->basename === '1.1.3-fake.test.php',
         description: 'File #3 (fake) basename: ' . $File3->basename
      );

      return true;
   }
];
