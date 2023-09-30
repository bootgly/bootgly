<?php


use Bootgly\ABI\Data\__String\Path;
use Bootgly\ACI\Tests\Assertions\Assertion;

return [
   // @ configure
   'describe' => 'It should return true if path is absolute',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @
      $Path = new Path('/etc/php/');

      Assertion::$description = 'Valid absolute path';
      yield assert($Path->absolute === true) ?: 'Path is absolute!';

      $Path = new Path('www/bootgly/index.php');
      yield new Assertion(
         assertion: $Path->absolute === false,
         description: 'Invalid relative path',
         fallback: 'Path is relative!'
      );
   }
];
