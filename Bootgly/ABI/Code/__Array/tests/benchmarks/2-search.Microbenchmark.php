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
   title: '__Array::search() vs array_search()',

   description: <<<TEXT
   The class adds two things native search does not: a {key, value, found}
   result, and a list of needles tried in order. This measures what each costs.
   TEXT,

   inputs: [
      'size' => 40,
      // ! Where the hit sits — move it to the front to measure an early exit
      'hit' => 30,
   ],

   Comparisons: static function (array $inputs): array {
      $haystack = Arrays::build(Shapes::Strings, $inputs['size']);

      $hit = "value{$inputs['hit']}";
      $miss = 'absent';

      return [new Comparison(
         name: "list of {$inputs['size']}, hit at {$inputs['hit']}",
         Cases: [
            'native array_search (hit)' => static fn () => array_search($hit, $haystack, true),
            'native + build the pair (hit)' => static function () use ($hit, $haystack) {
               $key = array_search($hit, $haystack, true);

               return [
                  'key' => $key,
                  'value' => $key === false ? null : $haystack[$key],
                  'found' => $key !== false,
               ];
            },
            '__Array::search (hit)' => static fn () => __Array::search($haystack, $hit, true),
            '__Array::search (miss)' => static fn () => __Array::search($haystack, $miss, true),
            '__Array::search (needle list)' => static fn () => __Array::search($haystack, [$miss, $hit], true),
         ],
         baseline: 'native array_search (hit)',
         recommendation: 'native array_search for a key; __Array::search for a needle list or the full triple',
         verdict: 'Native search is the floor. __Array::search earns its cost only when you '
            . 'want the {key, value, found} triple without writing it out, or when trying '
            . 'several needles in order — which native search cannot express at all.',
      )];
   },
);
