<?php

use Generator;

use Bootgly\ABI\Data\__String\Path;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;

return [
   // @ configure
   'describe' => 'It should valid real paths',
   // @ simulate
   // ...
   // @ test
   'test' => new Assertions(Case: function (): Generator
   {
      // @
      // Valid
      $Path = new Path;
      // * Config
      $Path->real = true;
      $Path->construct('/usr/bin');
      yield new Assertion(
         description: 'Valid real path',
         fallback: 'Real path not exists!'
      )
         ->assert(
            actual: (string) $Path,
            expected: '/usr/bin',
         );

      // Invalid
      $Path = new Path;
      // * Config
      $Path->real = true;
      $Path->construct('/usr/bin/fakebootgly');
      yield new Assertion(
         description: 'Invalid real path',
         fallback: 'Fake path valid?!'
      )
         ->assert(
            actual: (string) $Path,
            expected: '',
         );
   })
];
