<?php

use Bootgly\ABI\IO\FS\Dir;


return [
   // @ configure
   'describe' => '',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      $Dir1 = new Dir(__DIR__);
      $pathnames = $Dir1->scan(recursive: false);
      $paths = count($pathnames) - 1;
      assert(
         assertion: (string) $pathnames[0] === __DIR__ . '/1.1-construct-real_dir.test.php',
         description: 'Scanned dir paths[0]: ' . $pathnames[0]
      );
      assert(
         assertion: (string) $pathnames[$paths] === __DIR__ . '/@.php',
         description: 'Scanned dir paths[-1]: ' . $pathnames[$paths]
      );      

      return true;
   }
];
