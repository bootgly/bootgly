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
   title: 'REUSE — building the chain once instead of per call',

   description: <<<TEXT
   A chain constructed per call pays for the object and the recorded stages
   every time. On a large array that cost disappears into the work; on a small
   one it IS the work, which is why a per-call pipeline only breaks even around
   n=20 and barely wins below that.

   Building it once and applying it per call removes that entirely — and small
   arrays are exactly what a framework hot path handles. A sweep of this
   codebase found every chained hot-path site sitting at n <= 8: header lists,
   HTTP method lists, middleware stacks, Range header parts.

   The sizes here are chosen to straddle that: 5 and 8 are the real hot-path
   sizes, 20 is where the per-call form starts paying for itself, 100 is where
   both forms are comfortable.
   TEXT,

   inputs: [
      'sizes' => [5, 8, 20, 100],
   ],

   Gate: static fn (array $inputs): bool => Workloads::check(),

   Comparisons: static function (array $inputs): array {
      $Comparisons = [];

      $Transform = Workloads::$Transform;
      $Predicate = Workloads::$Test;

      foreach ($inputs['sizes'] as $size) {
         $array = Arrays::build(Shapes::Sequence, $size);

         // ! Built once — this is the subject, so it must NOT be inside the case
         $Reused = new Pipeline()->map($Transform)->filter($Predicate);

         $Comparisons[] = new Comparison(
            name: "n = {$size}",
            Cases: [
               'native chain' => static fn () => Workloads::chain($array),
               'Pipeline (constructed per call)' => static fn () => new Pipeline($array)
                  ->map($Transform)
                  ->filter($Predicate)
                  ->collect(),
               'Pipeline (built once, ->apply())' => static fn () => $Reused->apply($array),
               'hand-fused loop' => static fn () => Workloads::fuse($array),
            ],
            baseline: 'native chain',
            recommendation: 'Pipeline built once + ->apply() — the only form that wins at hot-path sizes',
            iterations: $size >= 100 ? 20000 : 50000,
            verdict: 'Construction is a fixed cost, so it decides the small sizes and vanishes '
               . 'at the large ones. Hoisting it out of the call is what gives the '
               . 'abstraction a hot path at all.',
         );
      }

      return $Comparisons;
   },

   conclusion: <<<TEXT
   STANDING CONCLUSION
   Build the chain where it is configured, not where it runs: at boot, in a
   constructor, in a static property. Then apply() it per request.

   A per-call chain is still the right choice for readability on arrays big
   enough not to care — but on the small arrays a server actually handles, reuse
   is the difference between winning and breaking even.
   TEXT,
);
