<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
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


class Loads
{
   /**
    * Load Loads from .lua files in directory.
    *
    * Metadata is extracted from comments:
    *   -- @label: <label>
    *   -- @group: <group>
    *   -- @opponents: <all|name1,name2>
    *
    * @param string $directory Absolute path to loads directory.
    *
    * @return array<Load>
    */
   public static function load (string $directory): array
   {
      $files = glob("$directory/*.lua");
      if ($files === false) {
         return [];
      }
      sort($files);

      $loads = [];

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

         // # opponents
         $opponents = 'all';
         if (preg_match('/^-- @opponents:\s*(.+)$/m', $content, $matches)) {
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

   /**
    * Load Loads from .php files in directory.
    *
    * Metadata is extracted from comments:
    *   // @label: <label>
    *   // @group: <group>
    *   // @opponents: <all|name1,name2>
    *
    * @param string $directory Absolute path to loads directory.
    *
    * @return array<Load>
    */
   public static function loadPhp (string $directory): array
   {
      $files = glob("$directory/*.php");
      if ($files === false) {
         return [];
      }
      sort($files);

      $loads = [];

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

         // # opponents
         $opponents = 'all';
         if (preg_match('/^\/\/ @opponents:\s*(.+)$/m', $content, $matches)) {
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
