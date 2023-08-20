<?php


use Bootgly\ABI\Data\__String\Path;


return [
   // @ configure
   'describe' => 'It should return path parts count',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @
      // Valid
      $Path = new Path('/var/www/bootgly/index.php');
      assert(
         assertion: $Path->indexes === 4,
         description: 'Returned path parts count: ' . $Path->indexes
      );

      return true;
   }
];
