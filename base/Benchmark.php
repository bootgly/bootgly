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


use Bootgly\Debugger\Backtrace;


class Benchmark
{
   // * Config
   public static bool $time = true;
   public static bool $memory = false;

   // * Data
   private static array $initial = [];
   private static array $final = [];
   public static array $results = [];

   // * Meta
   private static string $tag = '';


   public static function start (string $tag) : void
   {
      if (self::$time) {
         self::$initial[$tag]['time'] = microtime(true);
      }

      if (self::$memory) {
         self::$initial[$tag]['memory'] = memory_get_usage();
      }
   }
   public static function stop (string $tag)
   {
      if (self::$time) {
         self::$final[$tag]['time'] = microtime(true);

         // Results
         $initial = self::$initial[$tag]['time'];
         $final = self::$final[$tag]['time'];

         $result = round($final - $initial, 5);

         self::$results[$tag]['time'] = number_format($result, 6);
      }

      if (self::$memory) {
         self::$final[$tag]['memory'] = memory_get_usage();

         // Results
         $initial = self::$initial[$tag]['memory'];
         $final = self::$final[$tag]['memory'];

         self::$results[$tag]['memory'] = $final - $initial;
      }

      self::$tag = $tag;

      return Benchmark::class;
   }

   public static function show (? string $tag = null)
   {
      if (!$tag && self::$tag) {
         $tag = self::$tag;
      } else {
         return Benchmark::class;
      }

      $result = PHP_EOL . '=-=-=-=-=-=-' . PHP_EOL;

      $result .= 'Benchmark results for: ' . $tag . PHP_EOL . PHP_EOL;

      if (self::$time) {
         $result .= 'CPU time spent: ' . (string) self::$results[$tag]['time'] . 's' . PHP_EOL;
      }

      if (self::$memory) {
         $result .= 'RAM memory usage: ' . (string) self::$results[$tag]['memory'] . PHP_EOL;
      }

      $result .= PHP_EOL . '=-=-=-=-=-=-';

      echo $result;

      return Benchmark::class;
   }
   public static function save (? string $tag = null)
   {
      if (!$tag && self::$tag) {
         $tag = self::$tag;
      } else {
         return Benchmark::class;
      }

      $Backtrace = new Backtrace;
      $relativePath = Path::relativize(from: HOME_BASE, to: $Backtrace->file);
      $file = HOME_DIR . 'workspace/bench.marks';

      // @ Build data
      $header = "[$tag@$relativePath:$Backtrace->line]:";
      $body = self::$results[$tag]['time'];

      // @ Read file if exists
      $lines = file($file, FILE_IGNORE_NEW_LINES);

      // @ Search line to write
      $line = array_search($header, $lines);

      // @ Insert new line
      if ($line) {
         array_splice($lines, $line + 1, 0, $body);
      } else {
         $lines[] =  "\n" . $header . "\n" . $body . "\n";
      }

      // @ Build new file data
      $data = implode("\n", $lines);

      // @ Write to file
      file_put_contents($file, $data);

      return Benchmark::class;
   }
}
