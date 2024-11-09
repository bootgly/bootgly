<?php

use Generator;

use Bootgly\ABI\Data\__String\Path;
use Bootgly\ACI\Tests\Cases\Assertion;
use Bootgly\ACI\Tests\Cases\Assertions;


return [
   // @ configure
   'describe' => 'It should return parent dir',
   // @ simulate
   // ...
   // @ test
   'test' => new Assertions(Case: function (): Generator
   {
      // @
      // ! Dir
      // ? Absolute
      $Path = new Path('/etc/php/');
      yield new Assertion(
         description: 'Valid - absolute dir (2 parts)',
         fallback: "Path #11 - parent: {$Path->parent}"
      )
         ->assert(
            actual: $Path->parent,
            expected: '/etc/',
         );

      $Path = new Path('/etc/');
      yield new Assertion(
         description: 'Valid - absolute dir (1 part)',
         fallback: "Path #12 - parent: {$Path->parent}"
      )
         ->assert(
            actual: $Path->parent,
            expected: '/',
         );

      $Path = new Path('/');
      yield new Assertion(
         description: 'Valid - absolute dir (0 part)',
         fallback: "Path #13 - parent: {$Path->parent}"
      )
         ->assert(
            actual: $Path->parent,
            expected: '/',
         );

      // ? Relative
      $Path = new Path('etc/php/');
      yield new Assertion(
         description: 'Valid - relative dir (2 parts)',
         fallback: "Path #21 - parent: {$Path->parent}"
      )
         ->assert(
            actual: $Path->parent,
            expected: 'etc/',
         );

      $Path = new Path('etc/');
      yield new Assertion(
         description: 'Valid - relative dir (1 part)',
         fallback: "Path #22 - parent: {$Path->parent}"
      )
         ->assert(
            actual: $Path->parent,
            expected: './',
         );

      $Path = new Path('');
      yield new Assertion(
         description: 'Invalid - relative dir (0 part)',
         fallback: "Path #23 - parent: {$Path->parent}"
      )
         ->assert(
            actual: $Path->parent,
            expected: '',
         );

      // ! File
      // ? Absolute
      $Path = new Path('/var/test.php');
      yield new Assertion(
         description: 'Valid - absolute file (2 parts)',
         fallback: "Path #31 - parent: {$Path->parent}"
      )
         ->assert(
            actual: $Path->parent,
            expected: '/var/',
         );

      $Path = new Path('/test.php');
      yield new Assertion(
         description: 'Valid - absolute file (1 part)',
         fallback: "Path #32 - parent: {$Path->parent}"
      )
         ->assert(
            actual: $Path->parent,
            expected: '/',
         );

      $Path = new Path('/var/test');
      yield new Assertion(
         description: 'Valid - absolute file without extension (2 parts)',
         fallback: "Path #33 - parent: {$Path->parent}"
      )
         ->assert(
            actual: $Path->parent,
            expected: '/var/',
         );

      $Path = new Path('/test');
      yield new Assertion(
         description: 'Valid - absolute file without extension (1 part)',
         fallback: "Path #34 - parent: {$Path->parent}"
      )
         ->assert(
            actual: $Path->parent,
            expected: '/',
         );
   })
];
