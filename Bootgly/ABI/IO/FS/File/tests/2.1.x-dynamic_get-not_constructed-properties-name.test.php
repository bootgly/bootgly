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
      $File1 = new File;
      $File1->construct(__DIR__ . '/1.1-construct-real_file.test.php');
      assert(
         assertion: $File1->name === '1.1-construct-real_file.test',
         description: 'File #1 name: ' . $File1->name
      );

      $File2 = new File;
      $File2->construct(__DIR__ . '/1.1.3-fake.test.php');
      assert(
         assertion: $File2->name === '1.1.3-fake.test',
         description: 'File #2 (fake) name: ' . $File2->name
      );

      // @ Neutral
      $File3 = new File;
      $File3->construct('');
      assert(
         assertion: $File3->name === '',
         description: 'File #3 name: ' . $File3->name
      );

      // @ Invalid
      // ...

      return true;
   }
];
