<?php


use Generator;

use Bootgly\ABI\Data\__String\Path;
use Bootgly\ACI\Tests\Cases\Assertion;


return [
   // @ configure
   'describe' => 'It should return current part (base name)',
   // @ simulate
   // ...
   // @ test
   'test' => function (): Generator
   {
      // Relative paths
      $Path = new Path('etc');
      yield new Assertion(
         actual: $Path->current,
         expected: 'etc',
         description: 'Valid - relative base (0 part)',
         fallback: "Path: {$Path->current}"
      )->assert();

      $Path = new Path('etc/php');
      yield new Assertion(
         actual: $Path->current,
         expected: 'php',
         description: 'Valid - relative base (1 part)',
         fallback: "Path: {$Path->current}"
      )->assert();

      $Path = new Path('etc/php/8.4');
      yield new Assertion(
         actual: $Path->current,
         expected: '8.4',
         description: 'Valid - relative base (2 part)',
         fallback: "Path: {$Path->current}"
      )->assert();


      // Absolute paths
      $Path = new Path('/etc/php');
      yield new Assertion(
         actual: $Path->current,
         expected: 'php',
         description: 'Valid - absolute base (2 parts)',
         fallback: "Path: {$Path->current}"
      )->assert();

      $Path = new Path('/');
      yield new Assertion(
         actual: $Path->current,
         expected: '',
         description: 'Invalid - absolute dir (0 part)',
         fallback: "Path: {$Path->current}"
      )->assert();

      $Path = new Path('/etc/php/');
      yield new Assertion(
         actual: $Path->current,
         expected: 'php',
         description: 'Valid - absolute dir (2 parts)',
         fallback: "Path: {$Path->current}"
      )->assert();

      $Path = new Path('/etc/');
      yield new Assertion(
         actual: $Path->current,
         expected: 'etc',
         description: 'Valid - absolute dir (1 part)',
         fallback: "Path: {$Path->current}"
      )->assert();

      $Path = new Path('/var/test.php');
      yield new Assertion(
         actual: $Path->current,
         expected: 'test.php',
         description: 'Valid - absolute file with extension (2 parts)',
         fallback: "Path: {$Path->current}"
      )->assert();

      $Path = new Path('/test');
      yield new Assertion(
         actual: $Path->current,
         expected: 'test',
         description: 'Valid - absolute file without extension (1 part)',
         fallback: "Path: {$Path->current}"
      )->assert();
   }
];
