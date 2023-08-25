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
         assertion: $File1->parent === __DIR__ . DIRECTORY_SEPARATOR,
         description: 'File #1 parent: ' . $File1->parent
      );

      $File2 = new File;
      $File2->construct(__DIR__ . '/1.1.3-fake.test.php');
      assert(
         assertion: $File2->parent === __DIR__ . DIRECTORY_SEPARATOR,
         description: 'File #2 (fake) parent: ' . $File2->parent
      );

      $File3 = new File;
      $File3->construct('1.1.3-fake.test.php');
      assert(
         assertion: $File3->parent === './',
         description: 'File #3 (fake) parent: ' . $File3->parent
      );

      // @ Neutral
      $File4 = new File;
      $File4->construct('');
      assert(
         assertion: $File4->parent === '',
         description: 'File #4 parent: ' . $File3->parent
      );

      // @ Invalid
      // ...

      return true;
   }
];
