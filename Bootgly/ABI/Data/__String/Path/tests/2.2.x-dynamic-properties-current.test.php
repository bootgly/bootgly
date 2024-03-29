<?php


use Bootgly\ABI\Data\__String\Path;


return [
   // @ configure
   'describe' => 'It should return current part (base name)',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @
      // Valid - absolute dir (2 parts)
      $Path = new Path('/etc/php/');
      yield assert(
         assertion: $Path->current === 'php',
         description: 'Path #1 - current: ' . $Path->current
      );
      // Valid - absolute dir (1 part)
      $Path = new Path('/etc/');
      yield assert(
         assertion: $Path->current === 'etc',
         description: 'Path #2 - current: ' . $Path->current
      );
      // Invalid - absolute dir (0 part)
      $Path = new Path('/');
      yield assert(
         assertion: $Path->current === '',
         description: 'Path #3 - current: ' . $Path->current
      );
      // Valid - absolute file (2 parts)
      $Path = new Path('/var/test.php');
      yield assert(
         assertion: $Path->current === 'test.php',
         description: 'Path #4 - current: ' . $Path->current
      );
      // Valid - absolute file without extension (1 part)
      $Path = new Path('/test');
      yield assert(
         assertion: $Path->current === 'test',
         description: 'Path #5 - current: ' . $Path->current
      );
   }
];
