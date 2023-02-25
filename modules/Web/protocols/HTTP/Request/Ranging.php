<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\protocols\HTTP\Request;


trait Ranging
{
   public function range (int $size, string $header, array $options = ['combine' => true])
   {
      // @ Validate
      $equalIndex = strpos($header, '=');
      if ($equalIndex === false) {
         return -2; // @ Return malformed header string
      }

      // @ Split ranges
      $headerRanges = explode(',', substr($header, $equalIndex + 1));
      $ranges = [];

      // @ Iterate ranges
      for ($i = 0; $i < count($headerRanges); $i++) {
         $range = explode('-', $headerRanges[$i]);

         $start = (int) $range[0];
         $end = (int) $range[1];

         if (is_nan($start) || $range[0] === '') {
            $start = $size - $end;
            $end = $size - 1;
         } else if (is_nan($end) || $range[1] === '') {
            $end = $size - 1;
         }

         // @ Limit last-byte-pos to current length
         if ($end > $size - 1) {
            $end = $size - 1;
         }

         if (is_nan($start) || is_nan($end) || $start > $end || $start < 0) {
            continue;
         }

         $ranges[] = [
            'start' => $start,
            'end' => $end
         ];
      }

      if (count($ranges) < 1) {
         return -1; // Unsatisifiable range
      }

      $ranges['type'] = substr($header, 0, $equalIndex);

      return $ranges;
   }
}
