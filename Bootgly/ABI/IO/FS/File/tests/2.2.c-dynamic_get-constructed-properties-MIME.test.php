<?php

use Bootgly\ABI\IO\FS\File;


return [
   // @ configure
   'describe' => '',
   'separator.left' => '__get - Content',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      $File1 = new File;
      $File1->pathify(__DIR__ . '/1.1-construct-real_file.test.php');
      $MIME1 = $File1->MIME;
      assert(
         assertion: $MIME1->type === 'text/x-php',
         description: 'File #1 - MIME type: ' . $MIME1->type
      );

      // @ Neutral
      $File2 = new File;
      $File2->pathify('');
      $MIME2 = $File2->MIME;
      assert(
         assertion: $MIME2 === false,
         description: 'File #2 - MIME should be false'
      );

      // @ Invalid
      $File3 = new File;
      $File3->pathify(__DIR__ . '/1.1.3-fake.test.php');
      $MIME3 = $File3->MIME;
      assert(
         assertion: $MIME3 === false,
         description: 'File #3 (fake) - MIME should be false'
      );

      return true;
   }
];
