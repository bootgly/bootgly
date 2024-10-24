<?php


use Bootgly\ABI\Data\__String\Path;
use Bootgly\ACI\Tests\Cases\Assertion;

return [
   // @ configure
   'describe' => 'It should match paths',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      $Path = new Path;

      // @
      Assertion::$description = 'Valid absolute path';
      $Path->match(path: '/etc/php/%', pattern: '8.*');
      yield assert(
         assertion: (string) $Path === '/etc/php/8.0'
         || (string) $Path === '/etc/php/8.1'
         || (string) $Path === '/etc/php/8.2'
         || (string) $Path === '/etc/php/8.3'
         || (string) $Path === '/etc/php/8.4',
         description: 'PHP path #1 (absolute) not matched!'
      );

      Assertion::$description = 'Valid relative path';
      $Path = new Path('/etc/php/');
      $Path->match(path: '%', pattern: '8.*');
      yield assert(
         assertion: (string) $Path === '/etc/php/8.0'
         || (string) $Path === '/etc/php/8.1'
         || (string) $Path === '/etc/php/8.2'
         || (string) $Path === '/etc/php/8.3'
         || (string) $Path === '/etc/php/8.4',
         description: 'PHP path #2 (relative) not matched!'
      );
   }
];
