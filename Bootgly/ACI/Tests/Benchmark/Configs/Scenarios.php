<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Benchmark\Configs;


use const PATHINFO_FILENAME;
use function file_get_contents;
use function glob;
use function pathinfo;
use function preg_match;
use function sort;
use function trim;


class Scenarios
{
   /**
    * Load Scenarios from .lua files in directory.
    *
    * Metadata is extracted from comments:
    *   -- @label: <label>
    *   -- @group: <group>
    *   -- @competitors: <all|name1,name2>
    *
    * @param string $directory Absolute path to scenarios directory.
    *
    * @return array<Scenario>
    */
   public static function load (string $directory): array
   {
      $files = glob("$directory/*.lua");
      if ($files === false) {
         return [];
      }
      sort($files);

      $scenarios = [];

      foreach ($files as $file) {
         $content = file_get_contents($file);
         if ($content === false) {
            continue;
         }

         // @ Parse metadata
         // # label
         $label = '';
         if (preg_match('/^-- @label:\s*(.+)$/m', $content, $matches)) {
            $label = trim($matches[1]);
         }
         else {
            $label = pathinfo($file, PATHINFO_FILENAME);
         }

         // # group
         $group = '';
         if (preg_match('/^-- @group:\s*(.+)$/m', $content, $matches)) {
            $group = trim($matches[1]);
         }

         // # competitors
         $competitors = 'all';
         if (preg_match('/^-- @competitors:\s*(.+)$/m', $content, $matches)) {
            $competitors = trim($matches[1]);
         }

         $scenarios[] = new Scenario(
            label: $label,
            group: $group,
            file: $file,
            competitors: $competitors,
         );
      }

      return $scenarios;
   }

   /**
    * Load Scenarios from .php files in directory.
    *
    * Metadata is extracted from comments:
    *   // @label: <label>
    *   // @group: <group>
    *   // @competitors: <all|name1,name2>
    *
    * @param string $directory Absolute path to scenarios directory.
    *
    * @return array<Scenario>
    */
   public static function loadPhp (string $directory): array
   {
      $files = glob("$directory/*.php");
      if ($files === false) {
         return [];
      }
      sort($files);

      $scenarios = [];

      foreach ($files as $file) {
         $content = file_get_contents($file);
         if ($content === false) {
            continue;
         }

         // @ Parse metadata (PHP single-line comments)
         // # label
         $label = '';
         if (preg_match('/^\/\/ @label:\s*(.+)$/m', $content, $matches)) {
            $label = trim($matches[1]);
         }
         else {
            $label = pathinfo($file, PATHINFO_FILENAME);
         }

         // # group
         $group = '';
         if (preg_match('/^\/\/ @group:\s*(.+)$/m', $content, $matches)) {
            $group = trim($matches[1]);
         }

         // # competitors
         $competitors = 'all';
         if (preg_match('/^\/\/ @competitors:\s*(.+)$/m', $content, $matches)) {
            $competitors = trim($matches[1]);
         }

         $scenarios[] = new Scenario(
            label: $label,
            group: $group,
            file: $file,
            competitors: $competitors,
         );
      }

      return $scenarios;
   }
}
