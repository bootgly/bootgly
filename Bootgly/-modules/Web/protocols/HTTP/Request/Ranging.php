<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 *
 * PHP version adapted from NPM range/parser:
 * (https://github.com/jshttp/range-parser/blob/master/index.js)
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\protocols\HTTP\Request;


trait Ranging
{
   // * Meta
   public static int $multiparts = 0;


   public function range (int $size, string $header, bool $combine = false)
   {
      // @ Validate
      $equalIndex = strpos($header, '=');
      if ($equalIndex === false) {
         return -2; // @ Return malformed header string
      }

      // @ Split ranges
      $headerRanges = explode(',', substr($header, $equalIndex + 1));
      $ranges = [];

      // @ Iterate ranges (0-1,50-100,...)
      for ($i = 0; $i < count($headerRanges); $i++) {
         $range = explode('-', $headerRanges[$i]);

         if ( count($range) > 2 ) {
            return -1; // Unsatisifiable range
         }

         if ( $range[0] !== '' && ! ctype_digit($range[0]) ) {
            return -1; // Unsatisifiable range
         }
         if ( $range[1] !== '' && ! ctype_digit($range[1]) ) {
            return -1; // Unsatisifiable range
         }

         $start = (int) $range[0];
         $end = (int) $range[1];

         if ($range[0] === '') {
            $start = $size - $end;
            $end = $size - 1;
         } else if ($range[1] === '') {
            $end = $size - 1;
         }

         // @ Limit last-byte-pos to current length
         if ($end > $size - 1) {
            $end = $size - 1;
         }

         if ($start > $end || $start < 0) {
            continue;
         }

         $ranges[] = [
            'start' => $start,
            'end' => $end
         ];
      }

      if ( empty($ranges) ) {
         return -1; // Unsatisifiable range
      }

      if ($combine) {
         $ranges = $this->combineRanges($ranges);
      }

      $ranges['type'] = substr($header, 0, $equalIndex);

      return $ranges;
   }

   private function combineRanges (array $ranges)
   {
      // @ Map with index
      $ordered = array_map([$this, 'mapWithIndex'], $ranges, array_keys($ranges));
      // @ Sort by range start
      usort($ordered, function ($a, $b) {
         return $a['start'] - $b['start'];
      });
  
      for ($j = 0, $i = 1; $i < count($ordered); $i++) {
         $next = &$ordered[$i];
         $current = &$ordered[$j];

         if ($next['start'] > $current['end'] + 1) {
            // @ Next range
            $ordered[++$j] = $next;
         } else if ($next['end'] > $current['end']) {
            // @ Extend range
            $current['end'] = $next['end'];
            $current['index'] = min($current['index'], $next['index']);
         }
      }

      // @ Trim ordered array
      $ordered2 = array_slice($ordered, 0, $j + 1);

      // @ Generate combined range
      // @ Sort by range index
      usort($ordered2, function ($a, $b) {
         return $a['index'] - $b['index'];
      });
      // @ Map without index
      $combined = array_map([$this, 'mapWithoutIndex'], $ordered2);

      return $combined;
   }

   private function mapWithIndex ($range, $index) {
      return [
         'start' => $range['start'],
         'end' => $range['end'],
         'index' => $index
      ];
   }

   private function mapWithoutIndex ($range) {
      return [
         'start' => $range['start'],
         'end' => $range['end']
      ];
   }
}
