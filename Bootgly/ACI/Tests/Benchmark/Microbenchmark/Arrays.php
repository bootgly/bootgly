<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Benchmark\Microbenchmark;


use function range;

use Bootgly\ACI\Tests\Benchmark\Microbenchmark\Arrays\Shapes;


/**
 * Deterministic array inputs for microbenchmarks.
 *
 * Inputs are built here rather than inline in each case so that two benchmarks
 * measuring the same shape are provably measuring the SAME shape — a
 * comparison across case files is only meaningful when the input is identical.
 *
 * Every build is deterministic: no randomness, no clock, no environment. A
 * microbenchmark that cannot be reproduced is not evidence.
 */
class Arrays
{
   /**
    * Build an input array of the given shape and size.
    *
    * @return array<mixed>
    */
   public static function build (Shapes $Shape, int $size): array
   {
      // ? Nothing to build
      if ($size < 1) {
         return [];
      }

      // :
      return match ($Shape) {
         Shapes::Sequence => range(1, $size),

         Shapes::Map => self::hash($size),

         Shapes::Strings => self::pack($size),

         Shapes::Nested => self::sink($size),
      };
   }

   /**
    * Hashed keys, string values.
    *
    * @return array<string,string>
    */
   private static function hash (int $size): array
   {
      $array = [];
      for ($i = 0; $i < $size; $i++) {
         $array["key{$i}"] = "value{$i}";
      }

      return $array;
   }

   /**
    * Packed keys, string values.
    *
    * @return array<int,string>
    */
   private static function pack (int $size): array
   {
      $array = [];
      for ($i = 0; $i < $size; $i++) {
         $array[] = "value{$i}";
      }

      return $array;
   }

   /**
    * Packed ints with the only nested entry last — a depth check that
    * short-circuits cannot get lucky here.
    *
    * @return array<int,mixed>
    */
   private static function sink (int $size): array
   {
      $array = [];
      for ($i = 0; $i < $size - 1; $i++) {
         $array[] = $i;
      }
      $array[] = ['deep'];

      return $array;
   }
}
