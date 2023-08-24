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
      $File = new File;
      $File->construct(__DIR__ . '/1.1-construct-real_file.test.php');

      assert(
         assertion: $File->exists,
         description: 'File should exist!'
      );

      // @ Neutral
      // ...

      // @ Invalid
      $File = new File;
      $File->construct(__DIR__ . '/1.1.3-fake.test.php');

      assert(
         assertion: $File->exists === false,
         description: 'File should not exist!'
      );

      return true;
   }
];
