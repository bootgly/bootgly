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
         assertion: $File1->readable === true,
         description: 'File #1 - should be readable!'
      );

      // @ Neutral
      $File2 = new File('');
      assert(
         assertion: $File2->readable === false,
         description: 'File #2 - empty path - readable should be false'
      );

      // @ Invalid
      $File3 = new File(__DIR__ . '/1.1.3-fake.test.php');
      assert(
         assertion: $File3->readable === false,
         description: 'File #3 - fake file - readable should be false'
      );

      return true;
   }
];