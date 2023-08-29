<?php

use Bootgly\ABI\IO\FS\File;


return [
   // @ configure
   'describe' => '',
   'separator.line' => true,
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      // r
      $File10 = new File(__DIR__ . '/1.1-construct-real_file.test.php');
      $File10->open(File::READ_MODE);
      assert(
         assertion: is_resource($File10->handler),
         description: 'Invalid File handler in real File!'
      );

      // r+
      $File11 = new File(__DIR__ . '/&/testing.test.php');
      $File11->open(File::READ_NEW_MODE);
      assert(
         assertion: is_resource($File11->handler),
         description: 'Invalid File handler in new File!'
      );

      // @ Invalid
      $File21 = new File('');
      $File21->open(File::READ_MODE);
      assert(
         assertion: is_resource($File21->handler) === false,
         description: 'Invalid File handler in empty File!'
      );

      // ---
      $File22 = new File(__DIR__ . '/1.1.3-fake.test.php');
      $File22->open(File::READ_MODE);
      assert(
         assertion: is_resource($File22->handler) === false,
         description: 'Invalid File handler in fake File!'
      );

      return true;
   }
];
