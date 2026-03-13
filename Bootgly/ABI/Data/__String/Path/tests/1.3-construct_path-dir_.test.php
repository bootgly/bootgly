<?php


use Generator;

use Bootgly\ABI\Data\__String\Path;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should fix path separators',
   test: new Assertions(Case: function (): Generator
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
);
