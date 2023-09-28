<?php


use Bootgly\ABI\Data\__String\Path;


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
      self::$description = 'Valid - absolute dir (2 parts)';
      $Path = new Path('/etc/php/');
      yield assert(
         assertion: $Path->parent === '/etc/',
         description: 'Path #11 - parent: ' . $Path->parent
      );
      self::$description = 'Valid - absolute dir (1 part)';
      $Path = new Path('/etc/');
      yield assert(
         assertion: $Path->parent === '/',
         description: 'Path #12 - parent: ' . $Path->parent
      );
      self::$description = 'Valid - absolute dir (0 part)';
      $Path = new Path('/');
      yield assert(
         assertion: $Path->parent === '/',
         description: 'Path #13 - parent: ' . $Path->parent
      );

      // ? Relative
      self::$description = 'Valid - relative dir (2 parts)';
      $Path = new Path('etc/php/');
      yield assert(
         assertion: $Path->parent === 'etc/',
         description: 'Path #21 - parent: ' . $Path->parent
      );
      self::$description = 'Valid - relative dir (1 part)';
      $Path = new Path('etc/');
      yield assert(
         assertion: $Path->parent === './', // Valid?
         description: 'Path #22 - parent: ' . $Path->parent
      );
      self::$description = 'Invalid - relative dir (0 part)';
      $Path = new Path('');
      yield assert(
         assertion: $Path->parent === '',
         description: 'Path #23 - parent: ' . $Path->parent
      );


      // ! File
      // ? Absolute
      self::$description = 'Valid - absolute file (2 parts)';
      $Path = new Path('/var/test.php');
      yield assert(
         assertion: $Path->parent === '/var/',
         description: 'Path #31 - parent: ' . $Path->parent
      );
      self::$description = 'Valid - absolute file (1 part)';
      $Path = new Path('/test.php');
      yield assert(
         assertion: $Path->parent === '/',
         description: 'Path #32 - parent: ' . $Path->parent
      );
      self::$description = 'Valid - absolute file without extension (2 parts)';
      $Path = new Path('/var/test');
      yield assert(
         assertion: $Path->parent === '/var/',
         description: 'Path #33 - parent: ' . $Path->parent
      );
      self::$description = 'Valid - absolute file without extension (1 part)';
      $Path = new Path('/test');
      yield assert(
         assertion: $Path->parent === '/',
         description: 'Path #34 - parent: ' . $Path->parent
      );
   }
];
