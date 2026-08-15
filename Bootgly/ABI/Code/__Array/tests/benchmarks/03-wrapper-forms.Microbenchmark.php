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

// ! Prototype — static method (the cheapest possible wrapper)
final class WrappedStatic
{
   /**
    * @param array<mixed> $array
    * @return array<int|string>
    */
   public static function keys (array $array): array
   {
      return array_keys($array);
   }
}
// ! Prototype — property hook (the shape __Array and __String use)
final class WrappedHook
{
   /** @var array<mixed> */
   public array $array;

   /** @var array<int|string> */
   public array $keys {
      get => array_keys($this->array);
   }

   /**
    * @param array<mixed> $array
    */
   public function __construct (array $array)
   {
      $this->array = $array;
   }
}
// ! Prototype — magic __get (the pre-1.0 __Array shape, kept for the record)
final class WrappedMagic
{
   /** @var array<mixed> */
   public array $array;

   /**
    * @param array<mixed> $array
    */
   public function __construct (array $array)
   {
      $this->array = $array;
   }

   public function __get (string $property): mixed
   {
      return match ($property) {
         'keys' => array_keys($this->array),
         default => null,
      };
   }
}


return new Microbenchmark(
   title: 'STUDY — the cost of every wrapper form',

   description: <<<TEXT
   The reference measurement behind the rule "do not route framework array
   usage through a wrapper". It compares every formulation PHP offers against
   the native call, on a HEAVY operation and on a CHEAP one, so the shape of the
   overhead is visible: it is roughly constant, which means the lighter the
   operation the worse the ratio.

   The prototypes above are measurement subjects, not shipped code.
   TEXT,

   inputs: [
      'size' => 50,
   ],

   Comparisons: static function (array $inputs): array {
      $array = Arrays::build(Shapes::Map, $inputs['size']);

      $Hook = new WrappedHook($array);
      $Magic = new WrappedMagic($array);
      $__Array = new __Array($array);

      return [
         new Comparison(
            name: "HEAVY operation — array_keys() over {$inputs['size']} entries",
            Cases: [
               'native array_keys($a)' => static fn () => array_keys($array),
               'static method' => static fn () => WrappedStatic::keys($array),
               'property hook (instance reused)' => static fn () => $Hook->keys,
               'magic __get (instance reused)' => static fn () => $Magic->keys,
               'property hook + construction' => static fn () => new WrappedHook($array)->keys,
               'magic __get + construction' => static fn () => new WrappedMagic($array)->keys,
            ],
            baseline: 'native array_keys($a)',
            recommendation: 'native array_keys($a); if a wrapper is unavoidable, a static method — never magic __get',
            verdict: 'Real work dilutes the overhead — the cheapest wrapper form (a static '
               . 'method) lands within ~10%, while magic __get roughly doubles the cost.',
         ),
         new Comparison(
            name: 'CHEAP operation — the {key, value} boundary pair',
            Cases: [
               'native array_key_last + index' => static function () use ($array) {
                  $key = array_key_last($array);

                  return ['key' => $key, 'value' => $array[$key]];
               },
               '__Array ->Last (instance reused)' => static fn () => $__Array->Last,
               '__Array ->Last + construction' => static fn () => new __Array($array)->Last,
            ],
            baseline: 'native array_key_last + index',
            recommendation: 'native array_key_last + index — do not wrap cheap operations',
            verdict: 'Same absolute overhead, far less work to hide it, so the ratio blows up. '
               . 'Framework arrays (headers, route params, query args) are all cheap operations, '
               . 'which is why routing them through a wrapper is the wrong trade.',
         ),
      ];
   },

   conclusion: <<<TEXT
   STANDING CONCLUSION
   A PHP wrapper's floor is the native call it hides plus the dispatch, so there
   is no implementation to "swap in" that beats native. A wrapper buys
   expressiveness, never speed. Choose it for clarity, and only where the
   operation is heavy enough to dilute the dispatch.
   TEXT,
);
