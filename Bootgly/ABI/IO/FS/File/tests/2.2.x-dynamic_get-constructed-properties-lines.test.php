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
         assertion: $File->lines === 31,
         description: 'Invalid File lines count: ' . $File->lines
      );

      // @ Neutral
      // ...

      // @ Invalid
      // ...

      return true;
   }
];
