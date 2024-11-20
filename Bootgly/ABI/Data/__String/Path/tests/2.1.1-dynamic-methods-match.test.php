<?php

use Generator;

use Bootgly\ABI\Data\__String\Path;
use Bootgly\ACI\Tests\Assertion\Expectations\Matchers\VariadicDirPath;
use Bootgly\ACI\Tests\Cases\Assertion;
use Bootgly\ACI\Tests\Cases\Assertions;

return [
   // @ configure
   'describe' => 'It should match paths',
   // @ simulate
   // ...
   // @ test
   'test' => new Assertions(Case: function (): Generator
   {
      // @
      /*
         assertion: (string) $Path === '/etc/php/8.0'
         || (string) $Path === '/etc/php/8.1'
         || (string) $Path === '/etc/php/8.2'
         || (string) $Path === '/etc/php/8.3'
         || (string) $Path === '/etc/php/8.4'
      */
      $Path = new Path;
      $Path->match(path: '/etc/php/%', pattern: '8.*');
      yield new Assertion(
         description: 'Valid absolute path',
         fallback: 'PHP path #1 (absolute) not matched!'
      )
         ->assert(
            actual: (string) $Path,
            expected: '/etc/php/8.*',
            With: new VariadicDirPath
         );

      $Path = new Path('/etc/php/');
      $Path->match(path: '%', pattern: '8.*');
      yield new Assertion(
         description: 'Valid relative path',
         fallback: 'PHP path #2 (relative) not matched!'
      )
         ->assert(
            actual: (string) $Path,
            expected: '/etc/php/8.*',
            With: new VariadicDirPath
         );
   })
];
