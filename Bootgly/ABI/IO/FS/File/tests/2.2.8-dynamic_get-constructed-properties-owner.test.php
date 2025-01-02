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
         assertion: $File1->owner > 900 || $File1->owner < 10000,
         description: 'File #1 - owner: ' . $File1->owner
      );

      // @ Neutral
      $File2 = new File('');
      yield assert(
         assertion: $File2->owner === null,
         description: 'File #2 - empty path - owner should be null'
      );

      // @ Invalid
      $File3 = new File(__DIR__ . '/1.1.3-fake.test.php');
      yield assert(
         assertion: $File3->owner === null,
         description: 'File #3 - fake file - owner should be null'
      );
   }
];
