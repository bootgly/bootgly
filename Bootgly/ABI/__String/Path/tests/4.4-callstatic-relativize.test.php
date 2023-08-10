<?php


use Bootgly\ABI\__String\Path;


return [
   // @ configure
   'describe' => 'It should relativize paths',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      $relative = Path::relativize('/foo/bar/', '/foo/bar/tests/test2.php');
      assert(
         assertion: $relative === 'tests/test2.php',
         description: 'Relative path: ' . $relative
      );

      return true;
   }
];
