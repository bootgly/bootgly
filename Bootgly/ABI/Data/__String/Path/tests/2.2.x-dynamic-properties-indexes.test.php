<?php


use Generator;

use Bootgly\ABI\Data\__String\Path;
use Bootgly\ACI\Tests\Cases\Assertion;
use Bootgly\ACI\Tests\Cases\Assertions;


return [
   // @ configure
   'describe' => 'It should return path parts count',
   // @ simulate
   // ...
   // @ test
   'test' => new Assertions(Case: function (): Generator
   {
      // Valid
      $Path = new Path('/var/www/bootgly/index.php');
      yield new Assertion()
         ->assert(
            actual: $Path->indexes,
            expected: 4
         );
   })
];
