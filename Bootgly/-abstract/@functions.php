<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly;

// formatters
// @ ref: https://stackoverflow.com/questions/2510434/format-bytes-to-kilobytes-megabytes-gigabytes
function formatBytes ($bytes, $precision = 2) {
   $units = ['B', 'KB', 'MB', 'GB', 'TB'];

   $bytes = \max($bytes, 0);
   $pow = \floor(($bytes ? \log($bytes) : 0) / \log(1024));
   $pow = \min($pow, \count($units) - 1);

   // Uncomment one of the following alternatives
   $bytes /= pow(1024, $pow);
   // $bytes /= (1 << (10 * $pow));

   return \round($bytes, $precision) . ' ' . $units[$pow];
}
