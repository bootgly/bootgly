<?php


use Bootgly\ABI\Data\__String\Path;


return [
   // @ configure
   'describe' => 'It should concatenate paths',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      $path = Path::concatenate(['home', 'bootgly', 'bootgly', 'index.php'], offset: 2);
      yield assert(
         assertion: $path === 'bootgly/index.php',
         description: 'Path: ' . $path
      );
   }
];
