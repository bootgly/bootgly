<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data\__String;


use function log, floor, max, min, count, pow, round;


class Bytes
{
   public static function format (float|int $bytes, int $precision = 2): string
   {
      $units = ['B', 'KB', 'MB', 'GB', 'TB'];

      $bytes = (float) max($bytes, 0);
      $pow = min(
         floor(($bytes ? log($bytes): 0) / log(1024)),
         count($units) - 1
      );

      // Uncomment one of the following alternatives
      $bytes /= pow(1024, $pow);
      // $bytes /= (1 << (10 * $pow));

      return round($bytes, $precision) . ' ' . $units[$pow];
   }
}
