<?php


use Bootgly\ABI\Data\__String\Path;
use Bootgly\ACI\Tests\Cases\Assertion;

return [
   // @ configure
   'describe' => 'It should valid real paths',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @
      // Valid
      Assertion::$description = 'Valid path';
      $Path = new Path;
      // * Config
      $Path->real = true;
      $Path->construct('/usr/bin');
      yield assert(
         assertion: (string) $Path === '/usr/bin',
         description: 'Real path not exists!'
      );

      // Invalid
      Assertion::$description = 'Invalid path';
      $Path = new Path;
      // * Config
      $Path->real = true;
      $Path->construct('/usr/bin/fakebootgly');
      yield assert(
         assertion: (string) $Path === '',
         description: 'Fake path valid?!'
      );
   }
];
