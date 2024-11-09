<?php


use Generator;

use Bootgly\ABI\Data\__String\Path;
use Bootgly\ACI\Tests\Cases\Assertion;
use Bootgly\ACI\Tests\Cases\Assertions;


return [
   // @ configure
   'describe' => 'It should fix path separators',
   // @ simulate
   // ...
   // @ test
   'test' => new Assertions(Case: function (): Generator
   {
      $Path = new Path;
      // * Config
      // @ fix
      // Directory separators
      $Path->dir_ = true;

      // @
      $Path->construct('\home/bootgly\\');
      yield new Assertion(
         description: 'Path directory separators not fixed!'
      )
         ->assert(
            actual: (string) $Path,
            expected: '/home/bootgly/',
         );
   })
];
