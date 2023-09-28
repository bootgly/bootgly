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
         assertion: $File1->group > 900 || $File1->group < 10000,
         description: 'File #1 - group: ' . $File1->group
      );

      // @ Neutral
      $File2 = new File('');
      yield assert(
         assertion: $File2->group === false,
         description: 'File #2 - empty path - group should be false'
      );

      // @ Invalid
      $File3 = new File(__DIR__ . '/1.1.3-fake.test.php');
      yield assert(
         assertion: $File3->group === false,
         description: 'File #3 - fake file - group should be false'
      );
   }
];
