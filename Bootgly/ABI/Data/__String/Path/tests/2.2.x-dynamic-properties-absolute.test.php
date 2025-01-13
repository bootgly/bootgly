<?php

use Generator;

use Bootgly\ABI\Data\__String\Path;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;

return [
   // @ configure
   'describe' => 'It should return true if path is absolute',
   // @ simulate
   // ...
   // @ test
   'test' => new Assertions(Case: function (): Generator
   {
      // @
      $Path = new Path('/etc/php/');
      yield new Assertion(
         description: 'Valid absolute path',
         fallback: 'Path is absolute!'
      )
         ->assert(
            actual: $Path->absolute,
            expected: true,
         );

      $Path = new Path('www/bootgly/index.php');
      yield new Assertion(
         description: 'Invalid relative path',
         fallback: 'Path is relative!'
      )
         ->assert(
            actual: $Path->absolute,
            expected: false,
         );
   })
];
