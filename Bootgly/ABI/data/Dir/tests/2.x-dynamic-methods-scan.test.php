<?php

use Bootgly\ABI\data\Dir;


return [
   // @ configure
   'describe' => '',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      $Dir1 = new Dir;
      // * Config
      $Dir1->real = true;
      // @
      $Dir1->construct(__DIR__);

      $filenames = $Dir1->scan(recursive: false);
      $paths = count($filenames) - 1;

      assert(
         assertion: (string) $filenames[0] === __DIR__ . '/1.1-construct-real_dir.test.php',
         description: 'Scanned dir paths[0]: ' . $filenames[0]
      );
      assert(
         assertion: (string) $filenames[$paths] === __DIR__ . '/@.php',
         description: 'Scanned dir paths[-1]: ' . $filenames[$paths]
      );      

      return true;
   }
];
