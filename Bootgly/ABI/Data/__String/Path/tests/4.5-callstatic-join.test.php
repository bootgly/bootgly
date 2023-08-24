<?php


use Bootgly\ABI\Data\__String\Path;


return [
   // @ configure
   'describe' => 'It should join path nodes',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      $path = Path::join(['home', 'bootgly']);
      assert(
         assertion: $path === 'home/bootgly',
         description: 'Path: ' . $path
      );

      $path = Path::join(['home', 'bootgly'], absolute: true);
      assert(
         assertion: $path === '/home/bootgly',
         description: 'Path: ' . $path
      );

      $path = Path::join(['home', 'bootgly'], absolute: true, dir: true);
      assert(
         assertion: $path === '/home/bootgly/',
         description: 'Path: ' . $path
      );

      return true;
   }
];