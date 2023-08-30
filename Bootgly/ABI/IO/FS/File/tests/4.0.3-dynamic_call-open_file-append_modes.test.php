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
      // Basedir valid
      // a
      $File10 = new File(__DIR__ . '/_fake_private_file-1.test.php');
      $File10->open(File::APPEND_WRITE_ONLY_MODE);
      assert(
         assertion: is_resource($File10->handler),
         description: 'Invalid File #1.0 handler in real File!'
      );
      $File10->close();

      // a+
      $File11 = new File(__DIR__ . '/_fake_private_file-2.test.php');
      $File11->open(File::APPEND_WRITE_READ_MODE);
      assert(
         assertion: is_resource($File11->handler),
         description: 'Invalid File #1.1 handler in new File!'
      );
      $File11->close();

      // @ Empty
      $File21 = new File('');
      $File21->open(File::APPEND_WRITE_ONLY_MODE);
      assert(
         assertion: is_resource($File21->handler) === false,
         description: 'Invalid File #2.1 handler in empty File!'
      );
      $File21->close();

      $File22 = new File('');
      $File22->open(File::APPEND_WRITE_READ_MODE);
      assert(
         assertion: is_resource($File21->handler) === false,
         description: 'Invalid File #2.2 handler in empty File!'
      );
      $File22->close();

      // @ Invalid
      // Basedir invalid
      $File31 = new File(__DIR__ . '/&/2.1.3-fake.test.php');
      $File31->open(File::APPEND_WRITE_ONLY_MODE);
      assert(
         assertion: is_resource($File31->handler) === false,
         description: 'Invalid File #3.1 handler in fake File!'
      );
      $File31->close();

      $File32 = new File(__DIR__ . '/&/2.1.3-fake.test.php');
      $File32->open(File::APPEND_WRITE_READ_MODE);
      assert(
         assertion: is_resource($File32->handler) === false,
         description: 'Invalid File #3.2 handler in fake File!'
      );
      $File32->close();

      return true;
   }
];
