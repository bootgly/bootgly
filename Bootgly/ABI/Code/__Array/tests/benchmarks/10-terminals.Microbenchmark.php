<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ABI\Code\__Array\Pipeline;
use Bootgly\ACI\Tests\Benchmark\Microbenchmark;
use Bootgly\ACI\Tests\Benchmark\Microbenchmark\Arrays;
use Bootgly\ACI\Tests\Benchmark\Microbenchmark\Arrays\Shapes;
use Bootgly\ACI\Tests\Benchmark\Microbenchmark\Comparison;

require_once __DIR__ . '/workloads.php';

return new Microbenchmark(
   title: 'TERMINALS — count() and reduce(), the two that answer without materializing',

   description: <<<TEXT
   collect() has to build a result; count() and reduce() do not, and the native
   idioms for both build one anyway. Asking "how many survive" through
   count(array_filter(array_map(...))) materializes two arrays to produce a
   single integer, and array_reduce() folds over a filtered array that had to
   exist first.

   Neither of these early-exits — they are here because they are the terminals
   whose win comes purely from not allocating, which makes them the cleanest
   measurement of what the intermediates alone cost.
   TEXT,

   inputs: [
      'sizes' => [20, 100, 1000],
   ],

   Gate: static function (array $inputs): bool {
      $array = Arrays::build(Shapes::Sequence, 40);
      $Transform = Workloads::$Transform;
      $Test = Workloads::$Test;
      $Sum = static fn (int $carry, int $value): int => $carry + $value;

      $expected = array_values(array_filter(array_map($Transform, $array), $Test));

      return (new Pipeline($array))->map($Transform)->filter($Test)->count() === count($expected)
         && (new Pipeline($array))->map($Transform)->filter($Test)->reduce($Sum, 0) === array_sum($expected);
   },

   Comparisons: static function (array $inputs): array {
      $Comparisons = [];

      $Transform = Workloads::$Transform;
      $Predicate = Workloads::$Test;
      $Sum = static fn (int $carry, int $value): int => $carry + $value;

      foreach ($inputs['sizes'] as $size) {
         $array = Arrays::build(Shapes::Sequence, $size);
         $iterations = $size >= 1000 ? 3000 : 20000;

         $Comparisons[] = new Comparison(
            name: "count, n = {$size}",
            Cases: [
               'native count(filter(map))' => static fn () => count(
                  array_filter(array_map($Transform, $array), $Predicate)
               ),
               'Pipeline ->count()' => static fn () => (new Pipeline($array))
                  ->map($Transform)
                  ->filter($Predicate)
                  ->count(),
            ],
            baseline: 'native count(filter(map))',
            recommendation: 'Pipeline ->count() — counting never needs the array it counts',
            iterations: $iterations,
            verdict: 'Two arrays are materialized to produce one integer. Counting as the pass '
               . 'goes needs neither.',
         );

         $Comparisons[] = new Comparison(
            name: "reduce, n = {$size}",
            Cases: [
               'native array_reduce(filter(map))' => static fn () => array_reduce(
                  array_filter(array_map($Transform, $array), $Predicate),
                  $Sum,
                  0
               ),
               'Pipeline ->reduce()' => static fn () => (new Pipeline($array))
                  ->map($Transform)
                  ->filter($Predicate)
                  ->reduce($Sum, 0),
               'hand-fused fold' => static function () use ($array, $Transform, $Predicate, $Sum) {
                  $carry = 0;

                  foreach ($array as $value) {
                     $mapped = $Transform($value);

                     if ( $Predicate($mapped) ) {
                        $carry = $Sum($carry, $mapped);
                     }
                  }

                  return $carry;
               },
            ],
            baseline: 'native array_reduce(filter(map))',
            recommendation: 'Pipeline ->reduce() — it folds inside the pass instead of after it',
            iterations: $iterations,
            verdict: 'array_reduce() cannot fold what has not been built yet, so the whole '
               . 'filtered array exists before the fold starts.',
         );
      }

      return $Comparisons;
   },

   conclusion: <<<TEXT
   STANDING CONCLUSION
   Terminals that answer a question rather than return a collection are where
   materializing is pure waste, and both win by the width of the intermediates
   alone — no early exit involved.
   TEXT,
);
