<?php


use Bootgly\ABI\__String\Path;


return [
   // @ configure
   'describe' => '',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      $Path = new Path(BOOTGLY_ROOT_DIR);

      assert(
         assertion: $Path->Path === BOOTGLY_ROOT_DIR,
         description: 'Path not matched!'
      );

      return true;
   }
];