<?php


use Bootgly\ABI\Data\__String\Path;
use Bootgly\ACI\Tests\Assertions\Assertion;

return [
   // @ configure
   'describe' => 'It should return path parts Index (object)',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @

      $Path = new Path('/var/www/bootgly/index.php');

      $lastKey = $Path->Index->Last->key;
      Assertion::$description = 'Return Index last key';
      yield assert(
         assertion: $lastKey === 3,
         description: 'Last index key returned: ' . $lastKey
      );

      Assertion::$description = 'Return Index last value';
      $lastValue = $Path->Index->Last->value;
      yield assert(
         assertion: $lastValue === 'index.php',
         description: 'Last index value returned: ' . $lastValue
      );
   }
];
