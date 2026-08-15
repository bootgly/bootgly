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
use Bootgly\ACI\Tests\Benchmark\Microbenchmark\Comparison;

return new Microbenchmark(
   title: 'EARLY EXIT — the largest win __Array has',

   description: <<<TEXT
   Asking a chain for the FIRST match, or merely whether one exists, is where
   materializing costs the most: the native idiom builds the whole filtered
   array before it can answer, while a chain that stops at the first survivor
   pays only for the elements it walked.

   PHP 8.4 added array_find() / array_any(), which do early-exit in C — so those
   are the honest baseline for a single filter, not array_filter(). The pipeline
   still wins from about n=100 up, because a C array function pays the full
   callback dispatch per element (~37 ns) while the same callback invoked from a
   JIT-compiled userland loop costs about half that. Where a chain is involved
   the native form has to materialize the map first, and the gap becomes an
   order of magnitude.

   The hit position is the axis that matters, so it is swept: a hit near the
   front is nearly free, a full miss is the worst case — and even the worst case
   wins, because no intermediate array is ever built.
   TEXT,

   inputs: [
      'sizes' => [100, 1000],
   ],

   Gate: static function (array $inputs): bool {
      $array = array_fill(0, 50, 1);
      $array[10] = 7;
      $Triple = static fn (int $value): int => $value * 3;
      $Hit = static fn (int $value): bool => $value === 21;

      return (new Pipeline($array))->map($Triple)->filter($Hit)->find() === 21
         && (new Pipeline($array))->map($Triple)->filter($Hit)->check() === true
         && (new Pipeline($array))->map($Triple)->filter($Hit)->count() === 1;
   },

   Comparisons: static function (array $inputs): array {
      $Comparisons = [];

      $Triple = static fn (int $value): int => $value * 3;
      $Hit = static fn (int $value): bool => $value === 21;
      $HitRaw = static fn (int $value): bool => $value === 7;

      foreach ($inputs['sizes'] as $size) {
         $iterations = $size >= 1000 ? 3000 : 20000;

         foreach ([
            '5%' => (int) ($size * 0.05),
            '50%' => (int) ($size * 0.5),
            'miss' => -1,
         ] as $where => $position) {
            // ! One planted needle, at a controlled distance from the front
            $array = array_fill(0, $size, 1);
            if ($position >= 0) {
               $array[$position] = 7;
            }

            $Comparisons[] = new Comparison(
               name: "chain -> first match, n = {$size}, hit at {$where}",
               Cases: [
                  'native chain then [0]' => static function () use ($array, $Triple, $Hit) {
                     $result = array_values(array_filter(array_map($Triple, $array), $Hit));

                     return $result[0] ?? null;
                  },
                  'native array_find(array_map())' => static fn () => array_find(
                     array_map($Triple, $array),
                     $Hit
                  ),
                  'Pipeline ->map->filter->find()' => static fn () => (new Pipeline($array))
                     ->map($Triple)
                     ->filter($Hit)
                     ->find(),
                  'hand foreach + return' => static function () use ($array, $Triple, $Hit) {
                     foreach ($array as $value) {
                        $mapped = $Triple($value);

                        if ( $Hit($mapped) ) {
                           return $mapped;
                        }
                     }

                     return null;
                  },
               ],
               baseline: 'native chain then [0]',
               recommendation: 'Pipeline ->find() — it ties the hand-written loop and beats every native form',
               iterations: $iterations,
               verdict: 'Materializing to answer "which one is first" is the expensive part. '
                  . 'Even array_find() over a mapped array pays for the map in full.',
            );

            $Comparisons[] = new Comparison(
               name: "chain -> any match, n = {$size}, hit at {$where}",
               Cases: [
                  'native array_filter !== []' => static fn () => array_filter(
                     array_map($Triple, $array),
                     $Hit
                  ) !== [],
                  'native array_any(array_map())' => static fn () => array_any(
                     array_map($Triple, $array),
                     $Hit
                  ),
                  'Pipeline ->map->filter->check()' => static fn () => (new Pipeline($array))
                     ->map($Triple)
                     ->filter($Hit)
                     ->check(),
               ],
               baseline: 'native array_filter !== []',
               recommendation: 'Pipeline ->check() — never materialize an array to ask whether it would be empty',
               iterations: $iterations,
               verdict: 'Building a filtered array to test it against [] is the most expensive '
                  . 'way to ask a yes/no question about an array.',
            );

            // ! Single filter — the one shape PHP 8.4 answers natively end to end
            $Comparisons[] = new Comparison(
               name: "single filter -> first match, n = {$size}, hit at {$where}",
               Cases: [
                  'native array_find (PHP 8.4, C)' => static fn () => array_find($array, $HitRaw),
                  'Pipeline ->filter->find()' => static fn () => (new Pipeline($array))
                     ->filter($HitRaw)
                     ->find(),
               ],
               baseline: 'native array_find (PHP 8.4, C)',
               recommendation: $size < 1000 && $where === '5%'
                  ? 'native array_find — with one filter and a hit near the front, C wins'
                  : 'Pipeline ->find() — a JIT-compiled userland loop dispatches callbacks cheaper than C does',
               iterations: $iterations,
               verdict: 'The only configuration the native call wins is a single filter whose '
                  . 'hit is a handful of elements in; past that, per-element callback '
                  . 'dispatch decides it, and userland wins that.',
            );
         }
      }

      return $Comparisons;
   },

   conclusion: <<<TEXT
   STANDING CONCLUSION
   find() and check() are the strongest reason __Array exists. Against the
   idiomatic chain they win 3x to 52x; against PHP 8.4's own early-exit calls
   they still win about 2x once the array is large enough for per-element
   dispatch to dominate.

   The win grows without bound in n and shrinks toward the front of the array —
   and even a full miss wins, because nothing is ever materialized.
   TEXT,
);
