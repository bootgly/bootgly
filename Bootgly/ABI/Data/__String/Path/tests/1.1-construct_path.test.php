<?php

use Generator;

use Bootgly\ABI\Data\__String\Path;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;


return [
   // @ configure
   'describe' => '',
   // @ simulate
   // ...
   // @ test
   'test' => new Assertions(Case: function (): Generator
   {
      $Path = new Path(BOOTGLY_ROOT_DIR);

      yield new Assertion(
         description: 'Path not matched!'
      )
         ->assert(
            actual: $Path->path,
            expected: BOOTGLY_ROOT_DIR
         );
   })
];
