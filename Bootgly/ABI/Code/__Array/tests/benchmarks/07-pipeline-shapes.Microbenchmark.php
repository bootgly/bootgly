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
   title: 'SHAPE DISPATCH — what deciding once instead of per element buys',

   description: <<<TEXT
   A pipeline that records operations has to decide, for every element, which
   operation comes next. The naive implementation walks the recorded list inside
   the element loop; the shipped one reads the SHAPE once and jumps to a
   dedicated loop for it (map-only, filter-only, map->filter, filter->map),
   falling back to the naive pass for anything else.

   That single decision is the difference between the abstraction winning and
   merely matching: the naive form costs 1.34x to 1.57x more, which is enough to
   put it BEHIND the native chain at small sizes it should be beating.

   This is also why the shipped op set is small. Every additional operation kind
   multiplies the shapes worth specializing, and an unspecialized shape falls
   back to the pass that loses.
   TEXT,

   inputs: [
      'sizes' => [5, 20, 100, 1000],
   ],

   Gate: static fn (array $inputs): bool => Workloads::check(),

   Comparisons: static function (array $inputs): array {
      $Comparisons = [];

      $Transform = Workloads::$Transform;
      $Predicate = Workloads::$Test;

      foreach ($inputs['sizes'] as $size) {
         $array = Arrays::build(Shapes::Sequence, $size);

         $Comparisons[] = new Comparison(
            name: "n = {$size}",
            Cases: [
               'native chain' => static fn () => Workloads::chain($array),
               'Generic (op-loop per element)' => static fn () => new Generic($array)
                  ->map($Transform)
                  ->filter($Predicate)
                  ->collect(),
               'Pipeline (shape-dispatched)' => static fn () => new Pipeline($array)
                  ->map($Transform)
                  ->filter($Predicate)
                  ->collect(),
               'hand-fused loop' => static fn () => Workloads::fuse($array),
            ],
            baseline: 'native chain',
            recommendation: 'Pipeline — the shipped shape dispatch; Generic is the prototype it replaced',
            iterations: match (true) {
               $size >= 1000 => 3000,
               $size >= 100 => 20000,
               default => 50000,
            },
            verdict: 'Dispatching once per chain rather than once per element is the entire '
               . 'margin. Nothing else about the two implementations differs.',
         );
      }

      return $Comparisons;
   },

   conclusion: <<<TEXT
   STANDING CONCLUSION
   Shape dispatch is what makes a recorded chain worth shipping. Keep the
   specialized loops for the shapes that actually occur, and keep the op set
   small enough that the specialized shapes stay the common case.
   TEXT,
);
