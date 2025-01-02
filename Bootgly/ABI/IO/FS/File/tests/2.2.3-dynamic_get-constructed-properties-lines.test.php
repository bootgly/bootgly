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
         assertion: $File1->lines === 27,
         description: 'Invalid File #1 lines count: ' . $File1->lines
      );

      // @ Neutral
      $File2 = new File('');
      yield assert(
         assertion: $File2->lines === null,
         description: 'File #2 lines: ' . $File2->lines
      );

      // @ Invalid
      $File3 = new File(__DIR__ . '/1.1.3-fake.test.php');
      yield assert(
         assertion: $File3->lines === null,
         description: 'File #3 lines should be null!'
      );
   }
];
