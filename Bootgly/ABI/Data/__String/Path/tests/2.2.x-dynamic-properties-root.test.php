<?php


use Bootgly\ABI\Data\__String\Path;


return [
   // @ configure
   'describe' => 'It should return root dir',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @
      // ! Dir
      // ? Absolute
      // Valid - absolute dir (2 parts)
      $Path = new Path('/etc/php/');
      yield assert(
         assertion: $Path->root === '/',
         description: 'Path #11 - root: ' . $Path->root
      );
      // Valid - absolute dir (1 part)
      $Path = new Path('/etc/');
      yield assert(
         assertion: $Path->root === '/',
         description: 'Path #12 - root: ' . $Path->root
      );
      // Valid - absolute dir (0 part)
      $Path = new Path('/');
      yield assert(
         assertion: $Path->root === '/',
         description: 'Path #13 - root: ' . $Path->root
      );

      // ? Relative
      // Invalid - relative dir (2 parts)
      $Path = new Path('etc/php/');
      yield assert(
         assertion: $Path->root === '',
         description: 'Path #21 - root: ' . $Path->root
      );
      // Invalid - relative dir (1 part)
      $Path = new Path('etc/');
      yield assert(
         assertion: $Path->root === '',
         description: 'Path #22 - root: ' . $Path->root
      );
      // Invalid - relative dir (0 part)
      $Path = new Path('');
      yield assert(
         assertion: $Path->root === '',
         description: 'Path #23 - root: ' . $Path->root
      );


      // ! File
      // ? Absolute
      // Valid - absolute file (2 parts)
      $Path = new Path('/var/test.php');
      yield assert(
         assertion: $Path->root === '/',
         description: 'Path #31 - root: ' . $Path->root
      );
      // Valid - absolute file (1 part)
      $Path = new Path('/test.php');
      yield assert(
         assertion: $Path->root === '/',
         description: 'Path #32 - root: ' . $Path->root
      );
      // Valid - absolute file without extension (2 parts)
      $Path = new Path('/var/test');
      yield assert(
         assertion: $Path->root === '/',
         description: 'Path #33 - root: ' . $Path->root
      );
      // Valid - absolute file without extension (1 part)
      $Path = new Path('/test');
      yield assert(
         assertion: $Path->root === '/',
         description: 'Path #34 - root: ' . $Path->root
      );
   }
];
