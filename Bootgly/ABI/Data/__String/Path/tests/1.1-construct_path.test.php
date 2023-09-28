<?php


use Bootgly\ABI\Data\__String\Path;


return [
   // @ configure
   'describe' => '',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      $Path = new Path(BOOTGLY_ROOT_DIR);

      yield assert(
         assertion: $Path->path === BOOTGLY_ROOT_DIR,
         description: 'Path not matched!'
      );
   }
];
