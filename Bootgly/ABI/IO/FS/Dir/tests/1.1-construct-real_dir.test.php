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
      // absolute real base to real dir
      $Dir10 = new Dir;
      // * Config
      $Dir10->real = true;
      // @
      $Dir10->construct(__DIR__);
      assert(
         assertion: (string) $Dir10 === __DIR__ . DIRECTORY_SEPARATOR,
         description: 'Invalid Real Dir #1.0 (absolute real base to real dir): ' . $Dir10
      );
      // absolute real dir to real dir
      $Dir11 = new Dir;
      // * Config
      $Dir11->real = true;
      // @
      $Dir11->construct(__DIR__ . DIRECTORY_SEPARATOR);
      assert(
         assertion: (string) $Dir11 === __DIR__ . DIRECTORY_SEPARATOR,
         description: 'Invalid Real Dir #1.1 (absolute real dir to real dir): ' . $Dir11
      );
      // absolute real file to real dir
      $Dir12 = new Dir;
      // * Config
      $Dir12->real = true;
      // @
      $Dir12->construct(__DIR__ . '/@.php');
      assert(
         assertion: (string) $Dir12 === __DIR__ . DIRECTORY_SEPARATOR,
         description: 'Invalid Real Dir #1.2 (absolute real file to real dir): ' . $Dir12
      );

      // @ Neutral
      // empty string to real dir
      $Dir20 = new Dir;
      // * Config
      $Dir20->real = true;
      // @
      $Dir20->construct('');
      assert(
         assertion: (string) $Dir20 === '',
         description: 'Invalid Dir #2.0 (empty string to real dir): ' . $Dir20
      );
      // root dir to real dir
      $Dir21 = new Dir;
      // * Config
      $Dir21->real = true;
      // @
      $Dir21->construct('/');
      assert(
         assertion: (string) $Dir21 === '/',
         description: 'Invalid Dir #2.1 (root dir to real dir): ' . $Dir21
      );

      // @ Invalid
      // absolute fake base to real dir
      $Dir31 = new Dir;
      // * Config
      $Dir31->real = true;
      // @
      $Dir31->construct('/fake/path/base');
      assert(
         assertion: (string) $Dir31 === '',
         description: 'Invalid Dir #3.1 (absolute fake base to real dir): ' . $Dir31
      );

      return true;
   }
];
