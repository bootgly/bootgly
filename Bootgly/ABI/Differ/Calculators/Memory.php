<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Differ\Calculators;


use function array_fill;
use function array_reverse;
use function array_slice;
use function count;
use function in_array;

use Bootgly\ABI\Differ\Calculating;


/**
 * Memory-efficient (Hirschberg-style) Longest Common Subsequence calculator.
 *
 * Uses O(n * m) time and O(min(n, m)) memory.
 * Preferred for very large inputs.
 */
final class Memory implements Calculating
{
   public function calculate (array $from, array $to): array
   {
      $cFrom = count($from);
      $cTo   = count($to);

      if ($cFrom === 0) {
         return [];
      }

      if ($cFrom === 1) {
         if (in_array($from[0], $to, true)) {
            return [$from[0]];
         }

         return [];
      }

      $i         = (int) ($cFrom / 2);
      $fromStart = array_slice($from, 0, $i);
      $fromEnd   = array_slice($from, $i);
      $llB       = $this->measure($fromStart, $to);
      $llE       = $this->measure(array_reverse($fromEnd), array_reverse($to));
      $jMax      = 0;
      $max       = 0;

      for ($j = 0; $j <= $cTo; $j++) {
         $m = $llB[$j] + $llE[$cTo - $j];

         if ($m >= $max) {
            $max  = $m;
            $jMax = $j;
         }
      }

      $toStart = array_slice($to, 0, $jMax);
      $toEnd   = array_slice($to, $jMax);

      return [
         ...$this->calculate($fromStart, $toStart),
         ...$this->calculate($fromEnd, $toEnd),
      ];
   }

   /**
    * @param  array<int, string> $from
    * @param  array<int, string> $to
    * @return array<int, int>
    */
   private function measure (array $from, array $to): array
   {
      $current = array_fill(0, count($to) + 1, 0);
      $cFrom   = count($from);
      $cTo     = count($to);

      for ($i = 0; $i < $cFrom; $i++) {
         $prev = $current;

         for ($j = 0; $j < $cTo; $j++) {
            if ($from[$i] === $to[$j]) {
               $current[$j + 1] = $prev[$j] + 1;
            }
            else if ($current[$j] > $prev[$j + 1]) {
               $current[$j + 1] = $current[$j];
            }
            else {
               $current[$j + 1] = $prev[$j + 1];
            }
         }
      }

      return $current;
   }
}
