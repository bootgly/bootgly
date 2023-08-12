<?php


use Bootgly\ABI\__String\Path;


return [
   // @ configure
   'describe' => 'It should valid real paths',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @
      // Valid
      $Path = new Path;
      // * Config
      // @ valid
      $Path->real = true;
      $Path->construct('/usr/bin');
      assert(
         assertion: (string) $Path === '/usr/bin',
         description: 'Real path not exists!'
      );

      // Invalid
      $Path = new Path;
      // * Config
      // @ valid
      $Path->real = true;
      $Path->construct('/usr/bin/fakebootgly');
      assert(
         assertion: (string) $Path === '',
         description: 'Fake path valid?!'
      );

      return true;
   }
];
