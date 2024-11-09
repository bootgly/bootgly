<?php


use Generator;

use Bootgly\ABI\Data\__String\Path;
use Bootgly\ACI\Tests\Cases\Assertion;
use Bootgly\ACI\Tests\Cases\Assertions;


return [
   // @ configure
   'describe' => 'It should return current part (base name)',
   // @ simulate
   // ...
   // @ test
   'test' => new Assertions(Case: function (): Generator
   {
      // Relative paths
      $Path = new Path('etc');
      yield new Assertion(
         description: 'Valid - relative base (0 part)',
         fallback: "Path: {$Path->current}"
      )
         ->assert(
            actual: $Path->current,
            expected: 'etc',
         );

      $Path = new Path('etc/php');
      yield new Assertion(
         description: 'Valid - relative base (1 part)',
         fallback: "Path: {$Path->current}"
      )
         ->assert(
            actual: $Path->current,
            expected: 'php',
         );

      $Path = new Path('etc/php/8.4');
      yield new Assertion(
         description: 'Valid - relative base (2 part)',
         fallback: "Path: {$Path->current}"
      )
         ->assert(
            actual: $Path->current,
            expected: '8.4',
         );


      // Absolute paths
      $Path = new Path('/etc/php');
      yield new Assertion(
         description: 'Valid - absolute base (2 parts)',
         fallback: "Path: {$Path->current}"
      )
         ->assert(
            actual: $Path->current,
            expected: 'php',
         );

      $Path = new Path('/');
      yield new Assertion(
         description: 'Invalid - absolute dir (0 part)',
         fallback: "Path: {$Path->current}"
      )
         ->assert(
            actual: $Path->current,
            expected: '',
         );

      $Path = new Path('/etc/php/');
      yield new Assertion(
         description: 'Valid - absolute dir (2 parts)',
         fallback: "Path: {$Path->current}"
      )
         ->assert(
            actual: $Path->current,
            expected: 'php',
         );

      $Path = new Path('/etc/');
      yield new Assertion(
         description: 'Valid - absolute dir (1 part)',
         fallback: "Path: {$Path->current}"
      )
         ->assert(
            actual: $Path->current,
            expected: 'etc',
         );

      $Path = new Path('/var/test.php');
      yield new Assertion(
         description: 'Valid - absolute file with extension (2 parts)',
         fallback: "Path: {$Path->current}"
      )
         ->assert(
            actual: $Path->current,
            expected: 'test.php',
         );

      $Path = new Path('/test');
      yield new Assertion(
         description: 'Valid - absolute file without extension (1 part)',
         fallback: "Path: {$Path->current}"
      )
         ->assert(
            actual: $Path->current,
            expected: 'test',
         );
   })
];
