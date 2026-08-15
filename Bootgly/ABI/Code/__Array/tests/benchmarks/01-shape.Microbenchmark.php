<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ABI\Code\__Array;
use Bootgly\ACI\Tests\Benchmark\Microbenchmark;
use Bootgly\ACI\Tests\Benchmark\Microbenchmark\Arrays;
use Bootgly\ACI\Tests\Benchmark\Microbenchmark\Arrays\Shapes;
use Bootgly\ACI\Tests\Benchmark\Microbenchmark\Comparison;

return new Microbenchmark(
   title: '__Array shape — ->multidimensional vs the inline loop',

   description: <<<TEXT
   ->multidimensional has no native equivalent, so its honest baseline is the
   loop you would otherwise write inline.

   This case used to carry ->list as well. It measured 44.8 ns against a 4.4 ns
   array_is_list() — 10.2x, the worst trade in the class, over a relay that
   added nothing but dispatch. The member was cut on that number, so there is
   nothing left to measure; the number itself is the record.
   TEXT,

   inputs: [
      'size' => 20,
   ],

   Comparisons: static function (array $inputs): array {
      $size = $inputs['size'];

      $nested = Arrays::build(Shapes::Nested, $size);
      $Nested = new __Array($nested);

      return [
         new Comparison(
            name: '->multidimensional',
            Cases: [
               'inline foreach (no native equivalent)' => static function () use ($nested) {
                  foreach ($nested as $value) {
                     if ( is_array($value) ) {
                        return true;
                     }
                  }

                  return false;
               },
               '__Array ->multidimensional (reused)' => static fn () => $Nested->multidimensional,
               '__Array ->multidimensional (per call)' => static fn () => new __Array($nested)->multidimensional,
            ],
            baseline: 'inline foreach (no native equivalent)',
            recommendation: '__Array ->multidimensional when the intent matters; the inline foreach in hot paths',
            verdict: 'The closest call in the class: the work is a loop, so the dispatch is '
               . 'diluted rather than dominant. This is where __Array reads best — it names an '
               . 'intent PHP has no single call for.',
         ),
      ];
   },
);
