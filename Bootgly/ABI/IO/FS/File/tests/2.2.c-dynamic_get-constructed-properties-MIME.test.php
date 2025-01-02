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
      $File1 = new File(__DIR__ . '/1.1-construct-real_file.test.php');
      $MIME1 = $File1->MIME;
      yield assert(
         assertion: $MIME1->type === 'text/x-php',
         description: 'File #1 - MIME type: ' . $MIME1->type
      );

      // @ Neutral
      $File2 = new File('');
      $MIME2 = $File2->MIME;
      yield assert(
         assertion: $MIME2 === null,
         description: 'File #2 - MIME should be null'
      );

      // @ Invalid
      $File3 = new File(__DIR__ . '/1.1.3-fake.test.php');
      $MIME3 = $File3->MIME;
      yield assert(
         assertion: $MIME3 === null,
         description: 'File #3 (fake) - MIME should be null'
      );
   }
];
