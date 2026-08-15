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
   title: 'CHAIN FUSION — the case __Array exists for',
   description: <<<TEXT
   A native chain — array_values(array_filter(array_map(f, a), g)) — pays twice:
   an intermediate array per stage, and the full callback dispatch a C array
   function performs per element. A fused pass pays neither, and takes roughly
   two thirds off at every size measured.

   The question this case answers is whether an abstraction can keep that win.
   It can: __Array\Pipeline records the stages and runs them in one pass, and
   lands at PARITY with the same loop written out by hand — within 4% at n=100,
   within 3% at n=1000. The abstraction is free; the chain is what costs.

   Reused, it goes further: building the pipeline once and applying it per call
   removes the per-call construction that is the only thing keeping it behind on
   small arrays, and wins even at n=5 where the per-call form barely breaks even.

   Two historical notes, both mistakes worth keeping:
   1. An early SINGLE-RUN measurement reported the pipeline losing to the chain.
      That run was an outlier on a busy machine. Never conclude from one run.
   2. A later measurement reported the pipeline BEATING the hand-written loop by
      6-18%. That was run-to-run noise: writing the same loop four ways (closure,
      named function, static method, method call) lands them all within 4%. The
      honest claim is parity, not victory.
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

         // ! Built once, outside the measured closure — that is the point of it
         $Reused = new Pipeline()->map($Transform)->filter($Predicate);

         $Comparisons[] = new Comparison(
            name: "n = {$size}",
            Cases: [
               'native chain (2 intermediates)' => static fn () => Workloads::chain($array),
               'hand-fused loop (0 intermediates)' => static fn () => Workloads::fuse($array),
               'Pipeline (constructed per call)' => static fn () => new Pipeline($array)
                  ->map($Transform)
                  ->filter($Predicate)
                  ->collect(),
               'Pipeline (built once, ->apply())' => static fn () => $Reused->apply($array),
            ],
            baseline: 'native chain (2 intermediates)',
            recommendation: $size <= 8
               ? 'Pipeline built once + ->apply(); a per-call Pipeline barely breaks even this small'
               : 'Pipeline — it ties the hand-written loop and reads as the chain it replaces',
            iterations: match (true) {
               $size >= 1000 => 3000,
               $size >= 100 => 20000,
               default => 50000,
            },
            verdict: 'The intermediates and the C-level callback dispatch are what the native '
               . 'chain pays for; one pass with a userland callback pays neither. Only '
               . 'hand-inlining the transform so no callable is invoked at all goes faster, '
               . 'and no API can express that.',
         );
      }

      return $Comparisons;
   },

   conclusion: <<<TEXT
   STANDING CONCLUSION
   Fusion is a real win and an abstraction keeps it. The pipeline ties the
   hand-written fused loop and beats the idiomatic native chain by roughly 3x
   from n=20 up; built once and applied, it wins at every size measured.

   This is the one place a PHP-level array abstraction pays. Single operations
   are still a loss and always will be — the wrapper's floor is the native call
   plus its dispatch.
   TEXT,
);
