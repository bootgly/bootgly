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
      // fake dir to fake dir
      $Dir11 = new Dir;
      // * Config
      $Dir11->real = false;
      // @
      $Dir11->construct('/fake/dir/');
      assert(
         assertion: (string) $Dir11 === '/fake/dir/',
         description: 'Invalid Fake Dir #1.1 (absolute fake dir to fake dir): ' . $Dir11
      );

      // @ Neutral
      // empty string to fake dir
      $Dir20 = new Dir;
      // * Config
      $Dir20->real = false;
      // @
      $Dir20->construct('');
      assert(
         assertion: (string) $Dir20 === '',
         description: 'Invalid Fake Dir #2.0 (empty string to fake dir): ' . $Dir20
      );
      // root dir to fake dir
      $Dir21 = new Dir;
      // * Config
      $Dir21->real = false;
      // @
      $Dir21->construct('/');
      assert(
         assertion: (string) $Dir21 === '/',
         description: 'Invalid Fake Dir #2.1 (root dir to fake dir): ' . $Dir21
      );

      // @ Invalid
      // absolute fake file to fake dir
      $Dir31 = new Dir;
      // * Config
      $Dir31->real = false;
      // @
      $Dir31->construct('/fake/path/base/@.php');
      assert(
         assertion: (string) $Dir31 === '',
         description: 'Invalid Fake Dir #3.1 (absolute fake file to fake dir): ' . $Dir31
      );
      // absolute fake base to fake dir
      $Dir32 = new Dir;
      // * Config
      $Dir32->real = false;
      // @
      $Dir32->construct('/fake/path/base');
      assert(
         assertion: (string) $Dir32 === '',
         description: 'Invalid Fake Dir #3.2 (absolute fake base to fake dir): ' . $Dir32
      );

      return true;
   }
];
