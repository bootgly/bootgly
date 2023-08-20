<?php


use Bootgly\ABI\Data\__String\Path;


return [
   // @ configure
   'describe' => 'It should return true if path is relative',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @
      // Valid - relative
      $Path = new Path('www/bootgly/index.php');
      assert(
         assertion: $Path->relative === true,
         description: 'Path is relative!'
      );
      // Invalid - absolute
      $Path = new Path('/etc/php/');
      assert(
         assertion: $Path->relative === false,
         description: 'Path is absolute!'
      );

      return true;
   }
];
