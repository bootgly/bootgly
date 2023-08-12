<?php


use Bootgly\ABI\__String\Path;


return [
   // @ configure
   'describe' => 'It should return path type',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @
      // Valid
      $Path = new Path;
      $Path->real = true;
      $Path->construct('/etc/php/8.2/');

      assert(
         assertion: $Path->type === 'dir',
         description: 'Return Path type: ' . $Path->type
      );

      return true;
   }
];
