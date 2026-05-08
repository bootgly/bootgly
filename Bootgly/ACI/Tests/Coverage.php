<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests;


use function extension_loaded;
use function function_exists;
use function ksort;
use function preg_replace;
use function realpath;
use function str_contains;
use function str_replace;
use function strtolower;
use LogicException;

use Bootgly\ACI\Tests\Coverage\Driver;
use Bootgly\ACI\Tests\Coverage\Drivers\Nothing;


/**
 * Coverage session — owns one Driver and produces Reports.
 *
 *   $Coverage = new Coverage();          // auto-detect driver
 *   $Coverage->start();
 *   // ...run tests...
 *   $Coverage->stop();
 *   echo $Coverage->report('text');
 */
class Coverage
{
   /**
    * Live hit collector — written to by instrumented user code through
    * the static `hit()` entry point. Drivers may also seed/merge it.
    *
    * Shape: [absolute_file_path => [line_number => hits]]
    *
    * @var array<string, array<int, int>>
    */
   public static array $hits = [];

   /**
    * Active coverage backend used by this session.
    */
   public private(set) Driver $Driver;
   /**
    * Path fragments to include in the report (case-sensitive).
    * When non-empty, only files whose normalized path contains at
    * least one entry are kept.  Empty means "include everything"
    * (minus the always-excluded test scripts).
    *
    * @var array<string>
    */
   public array $includes = [];
   /**
    * Exact SUT file targets to keep in the report.
    *
    * When non-empty, files are kept only when their normalized canonical
    * path matches one of these targets. This keeps reports focused on the
    * subject-under-test even when runtime infrastructure executes.
    *
    * @var array<string>
    */
   public array $targets = [];
   /**
    * Show per-file coverage diff blocks in report formatters that support it.
    */
   public bool $diff = false;
   /**
    * @var array<string, array<int, int>>
    */
   public private(set) array $data = [];


   /**
    * Create a coverage session with an explicit Driver or auto-detected one.
    */
   public function __construct (null|Driver $Driver = null)
   {
      $this->Driver = $Driver ?? self::detect();
   }

   /**
    * Hit recorder — invoked by instrumented source files at runtime.
    * Kept extremely small to minimize per-line overhead.
    */
   public static function hit (string $file, int $line): void
   {
      self::$hits[$file][$line] = (self::$hits[$file][$line] ?? 0) + 1;
   }

   /**
    * Wipe the hit collector.
    */
   public static function reset (): void
   {
      self::$hits = [];
   }

   /**
    * Seed executable-line denominators (zero-hit) from a Universe map.
    *
    * @param array<string, array<int, int>> $map
    */
   public static function seed (array $map): void
   {
      foreach ($map as $file => $lines) {
         foreach ($lines as $line => $_) {
            self::$hits[$file][$line] ??= 0;
         }
      }
   }

   /**
    * Start recording coverage data through the configured Driver.
    */
   public function start (): void
   {
      $this->Driver->start();
   }

   /**
    * Stop the Driver, collect raw hits, and apply include/target filters.
    */
   public function stop (): void
   {
      $this->Driver->stop();
      $raw = $this->Driver->collect();

      $includes = [];
      foreach ($this->includes as $include) {
         $include = str_replace('\\', '/', $include);
         if ($include !== '') {
            $includes[] = $include;
         }
      }

      $targets = [];
      foreach ($this->targets as $target) {
         $targets[self::normalize($target)] = true;
      }

      $matchedTarget = $targets === [];
      if (! $matchedTarget) {
         foreach ($raw as $file => $_) {
            if (isset($targets[self::normalize($file)])) {
               $matchedTarget = true;
               break;
            }
         }
      }

      // Filter the raw hit map:
      //   - Always exclude test-script files (lowercase /tests/ dirs).
      //   - When $includes is set, keep only files matching a scope.
      //   - When $targets is set and present in raw data, keep only exact
      //     target file matches; otherwise keep the package include scope.
      $data = [];
      foreach ($raw as $file => $lines) {
         $canonical = self::normalize($file);
         $norm = $canonical;

         // Skip test scripts (e.g. Bootgly/ABI/Data/__Array/tests/1.x.test.php).
         // Note: the test-framework source lives under uppercase /Tests/, so
         // this case-sensitive check does NOT exclude those files.
         if (str_contains($norm, '/tests/')) {
            continue;
         }

         // If an include scope is set, only keep files within that scope.
         if ($includes !== []) {
            $match = false;
            foreach ($includes as $include) {
               if (str_contains($norm, $include)) {
                  $match = true;
                  break;
               }
            }
            if (!$match) continue;
         }

         // If exact SUT targets are set, keep only matched files.
         if ($matchedTarget && $targets !== [] && ! isset($targets[$canonical])) {
            continue;
         }

         if (! isset($data[$canonical])) {
            $data[$canonical] = [];
         }

         foreach ($lines as $line => $hits) {
            $line = (int) $line;
            $hits = (int) $hits;
            $current = $data[$canonical][$line] ?? 0;
            $data[$canonical][$line] = $hits > $current ? $hits : $current;
         }
      }

      ksort($data);
      foreach ($data as &$lines) {
         ksort($lines);
      }
      unset($lines);

      $this->data = $data;
   }

   /**
    * Render the captured hit map using a named report formatter.
    */
   public function report (string $format = 'text'): string
   {
      $class = match (strtolower($format)) {
         'text'   => Coverage\Reports\Text::class,
         'html'   => Coverage\Reports\HTML::class,
         'clover' => Coverage\Reports\Clover::class,
         default  => throw new LogicException("Coverage report not found: {$format}"),
      };

      $Report = new $class();
      $Report->diff = $this->diff;

      return $Report->render($this->data);
   }

   /**
    * Auto-detect the best available driver in priority order.
    */
   public static function detect (): Driver
   {
      if (function_exists('xdebug_start_code_coverage')) {
         return new Coverage\Drivers\XDebug();
      }

      if (extension_loaded('xdebug')) {
         throw new LogicException(
            'Xdebug was detected but coverage mode is disabled; '
            . 'set XDEBUG_MODE=coverage (or xdebug.mode=coverage).'
         );
      }

      if (extension_loaded('pcov')) {
         return new Coverage\Drivers\PCOV();
      }

      return new Nothing();
   }

   /**
    * Normalize a file path used as a coverage-map key.
    */
   private static function normalize (string $file): string
   {
      $resolved = realpath($file);
      $path = $resolved !== false ? $resolved : $file;
      $path = str_replace('\\', '/', $path);

      return preg_replace('#(?<!:)/{2,}#', '/', $path) ?? $path;
   }
}
