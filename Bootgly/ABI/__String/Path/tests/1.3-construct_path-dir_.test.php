<?php


use Bootgly\ABI\__String\Path;


return [
   // @ configure
   'describe' => 'It should fix path separators',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      $Path = new Path;
      // * Config
      // @ fix
      // Directory separators
      $Path->dir_ = true;

      // @
      $Path->construct('\home/bootgly\\');
      assert(
         assertion: (string) $Path === '/home/bootgly/',
         description: 'Path directory separators not fixed!'
      );

      return true;
   }
];
