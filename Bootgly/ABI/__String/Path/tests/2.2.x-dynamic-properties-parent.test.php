<?php


use Bootgly\ABI\__String\Path;


return [
   // @ configure
   'describe' => 'It should return parent dir',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @
      // ! Dir
      // ? Absolute
      // Valid - absolute dir (2 parts)
      $Path = new Path('/etc/php/');
      assert(
         assertion: $Path->parent === '/etc/',
         description: 'Path #11 - parent: ' . $Path->parent
      );
      // Valid - absolute dir (1 part)
      $Path = new Path('/etc/');
      assert(
         assertion: $Path->parent === '/',
         description: 'Path #12 - parent: ' . $Path->parent
      );
      // Valid - absolute dir (0 part)
      $Path = new Path('/');
      assert(
         assertion: $Path->parent === '/',
         description: 'Path #13 - parent: ' . $Path->parent
      );

      // ? Relative
      // Valid - relative dir (2 parts)
      $Path = new Path('etc/php/');
      assert(
         assertion: $Path->parent === 'etc/',
         description: 'Path #21 - parent: ' . $Path->parent
      );
      // Valid - relative dir (1 part)
      $Path = new Path('etc/');
      assert(
         assertion: $Path->parent === './', // Valid?
         description: 'Path #22 - parent: ' . $Path->parent
      );
      // Invalid - relative dir (0 part)
      $Path = new Path('');
      assert(
         assertion: $Path->parent === '',
         description: 'Path #23 - parent: ' . $Path->parent
      );


      // ! File
      // ? Absolute
      // Valid - absolute file (2 parts)
      $Path = new Path('/var/test.php');
      assert(
         assertion: $Path->parent === '/var/',
         description: 'Path #31 - parent: ' . $Path->parent
      );
      // Valid - absolute file (1 part)
      $Path = new Path('/test.php');
      assert(
         assertion: $Path->parent === '/',
         description: 'Path #32 - parent: ' . $Path->parent
      );
      // Valid - absolute file without extension (2 parts)
      $Path = new Path('/var/test');
      assert(
         assertion: $Path->parent === '/var/',
         description: 'Path #33 - parent: ' . $Path->parent
      );
      // Valid - absolute file without extension (1 part)
      $Path = new Path('/test');
      assert(
         assertion: $Path->parent === '/',
         description: 'Path #33 - parent: ' . $Path->parent
      );

      return true;
   }
];
