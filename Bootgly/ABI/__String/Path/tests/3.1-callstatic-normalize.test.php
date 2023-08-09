<?php


use Bootgly\ABI\__String\Path;


return [
   // @ configure
   'describe' => '',
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
