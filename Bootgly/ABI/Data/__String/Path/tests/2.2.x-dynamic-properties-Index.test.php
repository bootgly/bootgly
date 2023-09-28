<?php


use Bootgly\ABI\Data\__String\Path;


return [
   // @ configure
   'describe' => 'It should return path parts Index (object)',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @
      // Valid
      $Path = new Path('/var/www/bootgly/index.php');

      $lastKey = $Path->Index->Last->key;
      yield assert(
         assertion: $lastKey === 3,
         description: 'Last index key returned: ' . $lastKey
      );
      $lastValue = $Path->Index->Last->value;
      yield assert(
         assertion: $lastValue === 'index.php',
         description: 'Last index value returned: ' . $lastValue
      );
   }
];
