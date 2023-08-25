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
         assertion: $File1->exists,
         description: 'File #1 should exist!'
      );

      // @ Neutral
      $File2 = new File;
      $File2->construct('');
      assert(
         assertion: $File2->exists === false,
         description: 'File #2 should not exist!'
      );

      // @ Invalid
      $File3 = new File;
      $File3->construct(__DIR__ . '/1.1.3-fake.test.php');
      assert(
         assertion: $File3->exists === false,
         description: 'File #3 should not exist!'
      );

      return true;
   }
];
