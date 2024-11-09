<?php


use Generator;

use Bootgly\ABI\Data\__String\Path;
use Bootgly\ACI\Tests\Cases\Assertion;
use Bootgly\ACI\Tests\Cases\Assertions;

return [
   // @ configure
   'describe' => 'It should return path parts Index (object)',
   // @ simulate
   // ...
   // @ test
   'test' => new Assertions(Case: function (): Generator
   {
      // @
      $Path = new Path('/var/www/bootgly/index.php');

      $lastKey = $Path->Index->Last->key;
      yield new Assertion(
         description: 'Return Index last key',
         fallback: 'Last index key returned: ' . $lastKey
      )->assert(
         actual: $lastKey,
         expected: 3,
      );

      $lastValue = $Path->Index->Last->value;
      yield new Assertion(
         description: 'Return Index last value',
         fallback: 'Last index value returned: ' . $lastValue
      )->assert(
         actual: $lastValue,
         expected: 'index.php',
      );
   })
];

#HIGHLIGHT
