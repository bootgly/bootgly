<?php


use Bootgly\ABI\Data\__String\Path;


return [
   // @ configure
   'describe' => 'It should convert path to lowercase',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      $Path = new Path;
      // * Config
      // @ convert
      // to Lowercase
      $Path->convert = true;
      $Path->lowercase = true;

      // @
      $Path->construct('/HOME/BOotGly/');
      assert(
         assertion: (string) $Path === '/home/bootgly/',
         description: 'Path not converted to lowercase!'
      );

      return true;
   }
];
