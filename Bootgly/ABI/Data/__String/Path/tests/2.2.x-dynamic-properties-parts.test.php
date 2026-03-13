<?php

use Generator;

use Bootgly\ABI\Data\__String\Path;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should return path parts',
   test: new Assertions(Case: function (): Generator
   {
      // Valid
      $Path = new Path('/var/www/bootgly/index.php');
      yield new Assertion(
         description: 'Returned path parts',
         fallback: "Returned path parts: " . json_encode($Path->parts)
      )
         ->assert(
            actual: $Path->parts,
            expected: ['var', 'www', 'bootgly', 'index.php'],
         );
   })
);
