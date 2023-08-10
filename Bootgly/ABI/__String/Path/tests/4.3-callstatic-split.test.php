<?php


use Bootgly\ABI\__String\Path;


return [
   // @ configure
   'describe' => 'It should split paths',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      $paths1 = Path::split('/home/bootgly/');
      assert(
         assertion: count($paths1) === 2 && $paths1[0] === 'home' && $paths1[1] === 'bootgly',
         description: 'The path #1 was not split'
      );
      $paths2 = Path::split('\\home/bootgly/');
      assert(
         assertion: count($paths2) === 2 && $paths2[0] === 'home' && $paths2[1] === 'bootgly',
         description: 'The path #2 was not split'
      );
      $paths3 = Path::split('\\home\\bootgly/');
      assert(
         assertion: count($paths3) === 2 && $paths3[0] === 'home' && $paths3[1] === 'bootgly',
         description: 'The path #3 was not split'
      );

      return true;
   }
];
