<?php


use Bootgly\ABI\__String\Path;


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
      assert(
         assertion: (string) $Path === '/etc/php/8.0'
         || (string) $Path === '/etc/php/8.1'
         || (string) $Path === '/etc/php/8.2',
         description: 'PHP version not matched!'
      );

      return true;
   }
];
