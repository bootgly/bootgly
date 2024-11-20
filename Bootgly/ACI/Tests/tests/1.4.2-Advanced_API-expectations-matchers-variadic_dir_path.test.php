<?php

use Bootgly\ABI\Data\__String\Path;
use Generator;

use Bootgly\ACI\Tests\Assertion\Expectations\Matchers\VariadicDirPath;
use Bootgly\ACI\Tests\Cases\Assertion;
use Bootgly\ACI\Tests\Cases\Assertions;

return [
   // @ configure
   'describe' => 'It should compare using the matcher "VariadicDirPath"',
   // @ simulate
   // ...
   // @ test
   'test' => new Assertions(Case: function (): Generator
   {
      // Path
      $Path = new Path('/etc/php/');
      $Path->match(path: '%', pattern: '8.*');
      yield new Assertion(
         description: 'Valid relative path',
      )
         ->assert(
            actual: (string) $Path,
            expected: new VariadicDirPath('/etc/php/8.*'),
         );
   }),
];
