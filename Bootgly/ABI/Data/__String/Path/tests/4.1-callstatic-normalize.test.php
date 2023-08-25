<?php


use Bootgly\ABI\Data\__String\Path;


return [
   // @ configure
   'describe' => 'It should normalize path',
   'separator.left' => 'Static methods',
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
