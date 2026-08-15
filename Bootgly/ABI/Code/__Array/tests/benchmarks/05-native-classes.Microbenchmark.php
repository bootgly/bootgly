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

require_once __DIR__ . '/workloads.php';

return new Microbenchmark(
   title: 'STUDY — native classes & generators vs the function chain',

   description: <<<TEXT
   A Code API built purely out of PHP's array I/O functions is not the only
   native option. PHP also ships C-implemented classes and language machinery —
   SPL iterators, iterator decorators, SplFixedArray, generators — and for
   CHAINED operations those can beat the array_map/array_filter chain, because
   the chain's cost is not the calls but the intermediate arrays it
   materializes between stages.

   So for chained work the question is not "wrapper or native function" but
   WHICH native mechanism: functions, classes, or generators.
   TEXT,

   // ! The knob: sweep whatever sizes matter to you (`--sizes=8,64,4096`)
   inputs: [
      'sizes' => [5, 20, 100],
   ],

   // ? Every mechanism must agree with the baseline before any of it is timed
   Gate: static fn (array $inputs): bool => Workloads::check(),

   Comparisons: static function (array $inputs): array {
      $Comparisons = [];

      foreach ($inputs['sizes'] as $size) {
         // ! Fixture — scoped to this iteration, shared shape across cases
         $array = Arrays::build(Shapes::Sequence, $size);

         $Comparisons[] = new Comparison(
            name: "n = {$size}",
            Cases: [
               'function chain (array_map+array_filter)' => static fn () => Workloads::chain($array),
               'generator pipeline (C coroutine)' => static fn () => Workloads::generate($array),
               'SPL CallbackFilterIterator' => static fn () => Workloads::decorate($array),
               'SplFixedArray fused' => static fn () => Workloads::fix($array),
               'plain fused foreach' => static fn () => Workloads::fuse($array),
            ],
            baseline: 'function chain (array_map+array_filter)',
            recommendation: 'plain fused foreach in hot paths; a generator pipeline when the result is large or consumed lazily',
            iterations: $size >= 100 ? 20000 : 50000,
         );
      }

      return $Comparisons;
   },

   conclusion: <<<TEXT
   HOW TO READ THIS
   The function chain is the baseline because it is what most code writes.
   Anything beating it does so by not materializing an array between stages —
   that, not the function call, is the chain's real cost.

   "Native" is not one thing: generators win comfortably, SplFixedArray wins,
   and SPL iterator decorators lose badly by paying an object per element.
   Pick the mechanism, never the label.
   TEXT,
);
