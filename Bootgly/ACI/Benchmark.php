<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI;


use function array_search;
use function array_splice;
use function file;
use function file_put_contents;
use function implode;
use function memory_get_usage;
use function microtime;
use function number_format;
use function round;
use function touch;
use ErrorException;
use Throwable;

use Bootgly\ABI\Data\__String\Path;
use Bootgly\ABI\Debugging\Backtrace;


abstract class Benchmark
{
   // * Config
   public static bool $time = true;
   public static bool $memory = false;

   // * Data
   /** @var array<string,array{time:float,memory:int}> */
   private static array $initial = [];
   /** @var array<string,array{time:float,memory:int}> */
   private static array $final = [];
   /** @var array<string,array{time:string,memory:int}> */
   public static array $results = [];

   // * Metadata
   private static string $tag = '';


   public static function start (string $tag): void
   {
      if (self::$time) {
         self::$initial[$tag]['time'] = microtime(true);
      }

      if (self::$memory) {
         self::$initial[$tag]['memory'] = memory_get_usage();
      }
   }
   public static function stop (string $tag): string
   {
      if (self::$time) {
         self::$final[$tag]['time'] = microtime(true);

         // Results
         $initial = self::$initial[$tag]['time'];
         $final = self::$final[$tag]['time'];

         self::$results[$tag]['time'] = self::format($final, $initial);
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

   public static function format (float $initial, float $final, int $precision = 6): string
   {
      $result = round($final - $initial, $precision);

      $elapsed = number_format($result, $precision);

      return $elapsed;
   }
   public static function show (? string $tag = null): string
   {
      // ?!
      if (!$tag && !self::$tag) {
         return Benchmark::class; // TODO Exception or Error
      }
      $tag ??= self::$tag;

      // @
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
   public static function save (? string $tag = null): string
   {
      // ?!
      if (!$tag && !self::$tag) {
         return Benchmark::class; // TODO Exception or Error
      }
      $tag ??= self::$tag;

      // @
      try {
         $Backtrace = new Backtrace();

         // @ Prepare file
         $relativePath = Path::relativize(path: $Backtrace->file, from: BOOTGLY_WORKING_DIR);
         if ($relativePath === '') {
            throw new ErrorException('Relative path is empty!');
         }
         $file = BOOTGLY_WORKING_DIR . 'workdata/logs/benchmarks.log';
         touch($file);

         // @ Build data
         $header = "[$tag@$relativePath:$Backtrace->line]:";
         $body = self::$results[$tag]['time'];

         // @ Read file if exists
         $lines = file($file, FILE_IGNORE_NEW_LINES);
         if ($lines === false) {
            throw new ErrorException('Failed to read file!');
         }

         // @ Search line to write
         $line = array_search($header, $lines);

         // @ Insert new line
         if ($line) {
            array_splice($lines, $line + 1, 0, $body);
         }
         else {
            $lines[] =  "\n" . $header . "\n" . $body . "\n";
         }

         // @ Build new file data
         $data = implode("\n", $lines);

         // @ Write to file
         file_put_contents($file, $data);
      }
      catch (Throwable $T) {
         // Debugging\Exceptions::debug($T);
      }

      return Benchmark::class;
   }
}
