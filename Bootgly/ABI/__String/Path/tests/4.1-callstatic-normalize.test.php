<?php


use Bootgly\ABI\__String\Path;


return [
   // @ configure
   'describe' => 'It should normalize path',
   'separators' => [
      'left' => 'Static methods'
   ],
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      $normalized = Path::normalize('../../etc/passwd');

      assert(
         $normalized === 'etc/passwd',
         'Path not normalized!'
      );

      return true;
   }
];
