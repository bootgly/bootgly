<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\__String;


class Bytes
{
   public static function format ($bytes, $precision = 2) : string
   {
      $units = ['B', 'KB', 'MB', 'GB', 'TB'];

      $bytes = \max($bytes, 0);
      $pow = \floor(($bytes ? \log($bytes) : 0) / \log(1024));
      $pow = \min($pow, \count($units) - 1);

      // Uncomment one of the following alternatives
      $bytes /= pow(1024, $pow);
      // $bytes /= (1 << (10 * $pow));

      return \round($bytes, $precision) . ' ' . $units[$pow];
   }
}
