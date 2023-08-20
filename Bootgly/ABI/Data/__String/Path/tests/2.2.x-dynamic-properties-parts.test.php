<?php


use Bootgly\ABI\Data\__String\Path;


return [
   // @ configure
   'describe' => 'It should return path parts',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @
      // Valid
      $Path = new Path('/var/www/bootgly/index.php');
      assert(
         assertion: $Path->parts === ['var', 'www', 'bootgly', 'index.php'],
         description: 'Returned path parts: ' . json_encode($Path->parts)
      );

      return true;
   }
];
