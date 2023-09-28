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
      yield assert(
         assertion: $File1->writable === true,
         description: 'File #1 - should be writable!'
      );

      // @ Neutral
      $File2 = new File('');
      yield assert(
         assertion: $File2->writable === false,
         description: 'File #2 - empty path - writable should be false'
      );

      // @ Invalid
      $File3 = new File(__DIR__ . '/1.1.3-fake.test.php');
      yield assert(
         assertion: $File3->writable === false,
         description: 'File #3 - fake file - writable should be false'
      );
   }
];
