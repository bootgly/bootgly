<?php


use Bootgly\ABI\__String\Path;


return [
   // @ configure
   'describe' => 'It should valid real paths',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      $Path = new Path;
      // * Config
      // @ valid
      $Path->real = true;

      // @
      // Valid
      $Path->construct('/usr/bin');
      assert(
         assertion: (string) $Path === '/usr/bin',
         description: 'Real path not exists!'
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
