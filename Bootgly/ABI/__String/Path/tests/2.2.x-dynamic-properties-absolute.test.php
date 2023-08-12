<?php


use Bootgly\ABI\__String\Path;


return [
   // @ configure
   'describe' => 'It should return true if path is absolute',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @
      // Valid - absolute
      $Path = new Path('/etc/php/');
      assert(
         assertion: $Path->absolute === true,
         description: 'Path is absolute!'
      );
      // Invalid - relative
      $Path = new Path('www/bootgly/index.php');
      assert(
         assertion: $Path->absolute === false,
         description: 'Path is relative!'
      );

      return true;
   }
];
