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
use function count;

use Bootgly\ABI\Differ\Calculating;


/**
 * Time-efficient (table-based DP) Longest Common Subsequence calculator.
 *
 * Uses O(n * m) time and O(n * m) memory.
 * Preferred for small/medium inputs.
 */
final class Time implements Calculating
{
   public function calculate (array $from, array $to): array
   {
      $common     = [];
      $fromLength = count($from);
      $toLength   = count($to);
      $width      = $fromLength + 1;
      $matrix     = array_fill(0, $width * ($toLength + 1), 0);

      for ($i = 0; $i <= $fromLength; $i++) {
         $matrix[$i] = 0;
      }

      for ($j = 0; $j <= $toLength; $j++) {
         $matrix[$j * $width] = 0;
      }

      for ($i = 1; $i <= $fromLength; $i++) {
         for ($j = 1; $j <= $toLength; $j++) {
            $o = ($j * $width) + $i;

            // Avoid max() function-call overhead.
            $diagonal = $from[$i - 1] === $to[$j - 1]
               ? $matrix[$o - $width - 1] + 1
               : 0;
            $left = $matrix[$o - 1];
            $up   = $matrix[$o - $width];

            if ($left > $up) {
               $matrix[$o] = $diagonal > $left
                  ? $diagonal
                  : $left;
            }
            else {
               $matrix[$o] = $diagonal > $up
                  ? $diagonal
                  : $up;
            }
         }
      }

      $i = $fromLength;
      $j = $toLength;

      while ($i > 0 && $j > 0) {
         if ($from[$i - 1] === $to[$j - 1]) {
            $common[] = $from[$i - 1];
            $i--;
            $j--;
         }
         else {
            $o = ($j * $width) + $i;

            if ($matrix[$o - $width] > $matrix[$o - 1]) {
               $j--;
            }
            else {
               $i--;
            }
         }
      }

      return array_reverse($common);
   }
}
