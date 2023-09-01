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
      $File11 = new File(__DIR__ . '/1.1-construct-real_file.test.php');
      $File11->open(File::READONLY_MODE);
      assert(
         assertion: is_resource($File11->handler),
         description: 'Invalid File #1.1 handler in real File!'
      );
      $File11->close();

      // r+
      $File12 = new File(__DIR__ . '/1.1-construct-real_file.test.php');
      $File12->open(File::READ_WRITE_MODE);
      assert(
         assertion: is_resource($File12->handler),
         description: 'Invalid File #1.2 handler in real File!'
      );
      $File12->close();

      // @ Empty
      // r
      $File21 = new File('');
      $File21->open(File::READONLY_MODE);
      assert(
         assertion: is_resource($File21->handler) === false,
         description: 'Invalid File #2.1 handler in empty File!'
      );
      $File21->close();

      // r+
      $File22 = new File('');
      $File22->open(File::READ_WRITE_MODE);
      assert(
         assertion: is_resource($File22->handler) === false,
         description: 'Invalid File #2.2 handler in empty File!'
      );
      $File22->close();

      // @ Invalid
      // r
      $File31 = new File(__DIR__ . '/&/1.2.3-fake.test.php');
      $File31->open(File::READONLY_MODE);
      assert(
         assertion: is_resource($File31->handler) === false,
         description: 'Invalid File #3.1 handler in fake File!'
      );
      $File31->close();

      // r+
      $File32 = new File(__DIR__ . '/&/2.2.3-fake_natty.test.php');
      $File32->open(File::READ_WRITE_MODE);
      assert(
         assertion: is_resource($File32->handler) === false,
         description: 'Invalid File #3.2 handler in fake File!'
      );
      $File32->close();

      return true;
   }
];
