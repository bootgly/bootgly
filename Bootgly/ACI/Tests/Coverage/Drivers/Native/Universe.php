<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Coverage\Drivers\Native;


use Bootgly\ACI\Tests\Coverage\Drivers\Native;


/**
 * Coverage universe — the executable-line catalog (denominator) for
 * files that have been seen by the Native driver.
 *
 * Populated by `Compiler` while a file is being instrumented and
 * consumed by `Native::collect()` to merge zero-hit lines with the
 * runtime hit counters from `Bootgly\ACI\Tests\Coverage::$hits`.
 */
final class Universe
{
   /**
    * Executable-line denominator per file.
    * Shape: [file => [line => 0]]
    *
    * @var array<string, array<int, int>>
    */
   public static array $lines = [];
   /**
    * Statement span projections per file.
    * Shape: [file => [statement_start_line => [projected_line => true]]]
    *
    * @var array<string, array<int, array<int, true>>>
    */
   public static array $spans = [];
   /**
    * Case/default label projections per file.
    * Shape: [file => [label_line => statement_start_line]]
    *
    * @var array<string, array<int, int>>
    */
   public static array $labels = [];
   /**
    * Top-level declaration lines per file (class/interface/trait/enum).
    * Shape: [file => [line => true]]
    *
    * @var array<string, array<int, true>>
    */
   public static array $declarations = [];


   /**
    * Wipe the universe.
    */
   public static function reset (): void
   {
      self::$lines = [];
      self::$spans = [];
      self::$labels = [];
      self::$declarations = [];
   }

   /**
    * Register the executable lines of a file.
    *
    * @param array<int, int> $lines [line => 0] map.
      * @param array<int, array<int, true>> $spans
      * @param array<int, int> $labels
      * @param array<int, true> $declarations
    */
   public static function register (
      string $file,
      array $lines,
      array $spans = [],
      array $labels = [],
      array $declarations = []
   ): void
   {
      foreach ($spans as $projection) {
         foreach ($projection as $line => $_) {
            $lines[(int) $line] ??= 0;
         }
      }
      foreach ($labels as $line => $_) {
         $lines[(int) $line] ??= 0;
      }
      foreach ($declarations as $line => $_) {
         $lines[(int) $line] ??= 0;
      }

      if (isset(self::$lines[$file])) {
         self::$lines[$file] += $lines;
      }
      else {
         self::$lines[$file] = $lines;
      }

      if ($spans !== []) {
         if (! isset(self::$spans[$file])) {
            self::$spans[$file] = [];
         }
         foreach ($spans as $start => $projection) {
            $start = (int) $start;
            if (! isset(self::$spans[$file][$start])) {
               self::$spans[$file][$start] = [];
            }
            foreach ($projection as $line => $_) {
               self::$spans[$file][$start][(int) $line] = true;
            }
         }
      }

      if ($labels !== []) {
         if (! isset(self::$labels[$file])) {
            self::$labels[$file] = [];
         }
         foreach ($labels as $line => $start) {
            self::$labels[$file][(int) $line] = (int) $start;
         }
      }

      if ($declarations !== []) {
         if (! isset(self::$declarations[$file])) {
            self::$declarations[$file] = [];
         }
         foreach ($declarations as $line => $_) {
            self::$declarations[$file][(int) $line] = true;
         }
      }
   }

   /**
    * Merge denominator with hits to produce the final coverage map.
    *
    * @param array<string, array<int, int>> $hits Live hit counters.
    * @return array<string, array<int, int>>
    */
   public static function merge (array $hits, string $mode = Native::MODE_STRICT): array
   {
      $out = self::$lines;
      foreach ($hits as $file => $lines) {
         foreach ($lines as $line => $count) {
            $out[$file][$line] = $count;
         }
      }

      if ($mode !== Native::MODE_PARITY) {
         return $out;
      }

      foreach ($out as $file => &$lines) {
         $hasHit = false;
         foreach (($hits[$file] ?? []) as $count) {
            if ($count > 0) {
               $hasHit = true;
               break;
            }
         }

         // Top-level declarations are executed when the file is loaded.
         if ($hasHit) {
            foreach ((self::$declarations[$file] ?? []) as $line => $_) {
               $lines[(int) $line] = 1;
            }
         }

         // Mark switch case/default labels when their first statement executes.
         foreach ((self::$labels[$file] ?? []) as $label => $start) {
            if (($lines[(int) $start] ?? 0) > 0) {
               $lines[(int) $label] = 1;
            }
         }

         // Propagate statement hits to projected in-statement lines.
         foreach ((self::$spans[$file] ?? []) as $start => $projection) {
            if (($lines[(int) $start] ?? 0) <= 0) {
               continue;
            }
            foreach ($projection as $line => $_) {
               $lines[(int) $line] = 1;
            }
         }
      }
      unset($lines);

      return $out;
   }
}
