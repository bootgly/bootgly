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
         assertion: $File1->size === 514,
         description: 'File #1 size: ' . $File1->size
      );

      // @ Neutral
      $File2 = new File('');
      assert(
         assertion: $File2->size === false,
         description: 'File #2 should have size === false!'
      );

      // @ Invalid
      $File3 = new File(__DIR__ . '/1.1.3-fake.test.php');
      assert(
         assertion: $File3->size === false,
         description: 'File #3 should have size === false!'
      );

      return true;
   }
];