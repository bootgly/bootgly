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
      $File10 = new File(__DIR__ . '/1.1-construct-real_file.test.php');
      assert(
         assertion: $File10->basename === '1.1-construct-real_file.test.php',
         description: 'File #1.0 basename: ' . $File10->basename
      );

      // @ Neutral
      $File20 = new File('');
      assert(
         assertion: $File20->basename === '',
         description: 'File #2.0 basename: ' . $File20->basename
      );

      // @ Invalid
      $File30 = new File(__DIR__ . '/1.1.3-fake.test.php');
      assert(
         assertion: $File30->basename === '1.1.3-fake.test.php',
         description: 'File #3.0 (fake) basename: ' . $File30->basename
      );

      return true;
   }
];
