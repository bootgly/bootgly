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
   title: '__Array boundary — ->First / ->Last vs native',

   description: <<<TEXT
   When you want the boundary entry AND the key it sits at, is the {key, value}
   pair worth an object?
   TEXT,

   inputs: [
      'size' => 20,
   ],

   Comparisons: static function (array $inputs): array {
      $array = Arrays::build(Shapes::Map, $inputs['size']);

      $__Array = new __Array($array);

      return [new Comparison(
         name: "map of {$inputs['size']} entries",
         Cases: [
            'native array_key_last + index' => static function () use ($array) {
               $key = array_key_last($array);

               return ['key' => $key, 'value' => $array[$key]];
            },
            'native, value only' => static fn () => $array[array_key_last($array)],
            '__Array ->Last (instance reused)' => static fn () => $__Array->Last,
            '__Array ->Last (constructed per call)' => static fn () => new __Array($array)->Last,
            '__Array ->First (instance reused)' => static fn () => $__Array->First,
         ],
         baseline: 'native array_key_last + index',
         recommendation: 'native array_key_last + index — reach for ->Last only for readability, outside hot paths',
         verdict: 'The wrapper cannot beat the call it hides — its floor is that call plus the '
            . 'dispatch. ->Last earns its cost only where the {key, value} pair genuinely '
            . 'simplifies the caller; constructing one per call never pays.',
      )];
   },
);
