<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Benchmark\Configs;


use const PATHINFO_EXTENSION;
use const PATHINFO_FILENAME;
use function array_merge;
use function file_get_contents;
use function glob;
use function pathinfo;
use function preg_match;
use function sort;
use function trim;


class Loads
{
   /**
    * Load Loads from the .lua and .php files of a directory.
    *
    * Both extensions are read together, in sorted file order. Metadata is
    * extracted from single-line comments — `--` in Lua, `//` in PHP:
    *   -- @label: <label>              // @label: <label>
    *   -- @group: <group>              // @group: <group>
    *   -- @opponents: <all|name1,...>  // @opponents: <all|name1,...>
    *
    * @param string $directory Absolute path to loads directory.
    *
    * @return array<Load>
    */
   public static function load (string $directory): array
   {
      // ! One glob per extension — GLOB_BRACE is not portable (musl)
      $files = array_merge(
         glob("$directory/*.lua") ?: [],
         glob("$directory/*.php") ?: []
      );
      sort($files);

      $loads = [];

      // @@
      foreach ($files as $file) {
         $content = file_get_contents($file);
         if ($content === false) {
            continue;
         }

         // ! Comment marker by extension: Lua `--`, PHP `//`
         $marker = pathinfo($file, PATHINFO_EXTENSION) === 'lua' ? '--' : '//';

         // @ Parse metadata
         // # label
         $label = '';
         if (preg_match("~^$marker @label:\s*(.+)$~m", $content, $matches)) {
            $label = trim($matches[1]);
         }
         else {
            $label = pathinfo($file, PATHINFO_FILENAME);
         }

         // # group
         $group = '';
         if (preg_match("~^$marker @group:\s*(.+)$~m", $content, $matches)) {
            $group = trim($matches[1]);
         }

         // # opponents
         $opponents = 'all';
         if (preg_match("~^$marker @opponents:\s*(.+)$~m", $content, $matches)) {
            $opponents = trim($matches[1]);
         }

         $loads[] = new Load(
            label: $label,
            group: $group,
            file: $file,
            opponents: $opponents,
         );
      }

      return $loads;
   }
}
