<?php


use Generator;

use Bootgly\ABI\Data\__String\Path;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should convert path to lowercase',
   test: new Assertions(Case: function (): Generator
   {
      $Path = new Path;
      // * Config
      // @ convert
      // to Lowercase
      $Path->convert = true;
      $Path->lowercase = true;

      // @
      $Path->construct('/HOME/BOotGly/');
      yield new Assertion(
         description: 'Path not converted to lowercase!'
      )
         ->assert(
            actual: (string) $Path,
            expected: '/home/bootgly/',
         );
   })
);
