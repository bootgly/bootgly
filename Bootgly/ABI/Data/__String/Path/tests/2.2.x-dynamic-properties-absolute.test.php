<?php

use Bootgly\ABI\Data\__String\Path;
use Bootgly\ACI\Tests\Cases\Assertion;

return [
   // @ configure
   'describe' => 'It should return true if path is absolute',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @
      $Path = new Path('/etc/php/');
      yield new Assertion(
         actual: $Path->absolute,
         expected: true,
         description: 'Valid absolute path',
         fallback: 'Path is absolute!'
      )->assert();

      $Path = new Path('www/bootgly/index.php');
      yield new Assertion(
         actual: $Path->absolute,
         expected: false,
         description: 'Invalid relative path',
         fallback: 'Path is relative!'
      )->assert();
   }
];
