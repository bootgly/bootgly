<?php


use Bootgly\ABI\Data\__String\Path;


return [
   // @ configure
   'describe' => 'It should match paths',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      $Path = new Path;

      // @
      // Valid - absolute
      $Path->match(path: '/etc/php/%', pattern: '8.*');
      yield assert(
         assertion: (string) $Path === '/etc/php/8.0'
         || (string) $Path === '/etc/php/8.1'
         || (string) $Path === '/etc/php/8.2',
         description: 'PHP path #1 (absolute) not matched!'
      );
      // Valid - relative
      $Path = new Path('/etc/php/');
      $Path->match(path: '%', pattern: '8.*');
      yield assert(
         assertion: (string) $Path === '/etc/php/8.0'
         || (string) $Path === '/etc/php/8.1'
         || (string) $Path === '/etc/php/8.2',
         description: 'PHP path #2 (relative) not matched!'
      );
   }
];
