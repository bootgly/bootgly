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
      // absolute real file to real file
      $File10 = new File;
      $File10->construct(__DIR__ . '/@.php');

      assert(
         assertion: (string) $File10 === __DIR__ . '/@.php',
         description: 'Invalid Real File #1.0 (absolute real file): ' . $File10
      );

      // @ Neutral
      // ...

      // @ Invalid
      // ...

      return true;
   }
];
