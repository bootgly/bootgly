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
      $File1 = new File('/bin/sh');
      assert(
         assertion: $File1->link === 'dash',
         description: 'File #1 - should have link value!' . $File1->link
      );

      // @ Neutral
      $File2 = new File('');
      assert(
         assertion: $File2->link === false,
         description: 'File #2 - empty path - link should be false'
      );

      // @ Invalid
      $File3 = new File(__DIR__ . '/1.1.3-fake.test.php');
      assert(
         assertion: $File3->link === false,
         description: 'File #3 - fake file - link should be false'
      );

      return true;
   }
];
