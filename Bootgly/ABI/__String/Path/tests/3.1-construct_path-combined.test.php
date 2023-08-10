<?php


use Bootgly\ABI\__String\Path;


return [
   // @ configure
   'describe' => 'It should construct combined',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      $Path = new Path;
      // * Config
      // @ convert
      $Path->convert = true;
      $Path->lowercase = true;
      // @ fix
      $Path->fix = true;
      $Path->dir_ = true;
      $Path->normalize = true;
      // @ valid
      $Path->real = true;

      // @
      // Valid
      $Path->construct('\\ETC\/php\\8.2/..');
      assert(
         assertion: (string) $Path === '/etc/php',
         description: 'Combined path is not valid!'
      );
      // Invalid
      $Path->construct('/usr/bin/fakebootgly');
      assert(
         assertion: (string) $Path === '',
         description: 'Fake path valid?!'
      );

      return true;
   }
];
