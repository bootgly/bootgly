<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests;


use const BOOTGLY_WORKING_DIR;
use function array_intersect_key;
use function array_pop;
use function explode;
use function extension_loaded;
use function file_get_contents;
use function function_exists;
use function is_dir;
use function is_file;
use function is_readable;
use function ksort;
use function preg_replace;
use function realpath;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use FilesystemIterator;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use Bootgly\ACI\Tests\Coverage\Driver;
use Bootgly\ACI\Tests\Coverage\Drivers\Native;
use Bootgly\ACI\Tests\Coverage\Drivers\Native\Analyzer;
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
    * Resource directory names whose PHP files are not code to cover — an
    * unloaded source inside one never joins the report at zero hits.
    */
   public const array RESOURCES = [
      'examples' => true,
      'fixtures' => true,
      'stubs' => true,
      'templates' => true,
      'tests' => true,
   ];

   /**
    * Live hit collector — written to by instrumented user code through
    * the static `hit()` entry point. Drivers may also seed/merge it.
    *
    * Shape: [absolute_file_path => [line_number => hits]]
    *
    * @var array<string, array<int, int>>
    */
   public static array $hits = [];

   // * Config
   /**
    * Active coverage backend used by this session.
    */
   public private(set) Driver $Driver;

   // * Data
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
    * Directories measured in full: every PHP source under them that the
    * run never loaded joins the report at zero hits, so a floor counts
    * what no test reached. Their executable lines are estimated by the
    * static analyzer of the Native driver. Sources inside a resource
    * directory below the path (see `RESOURCES`), sources the
    * `$includes`/`$targets` filters reject and, under the Native driver,
    * sources it never instruments stay out.
    *
    * @var array<string>
    */
   public array $paths = [];
   /**
    * Changed lines to measure, `[canonical_file => [line => true]]`
    * (see `Coverage\Changes`). When set, a changed PHP source no test
    * loaded joins at zero hits (when the filters admit it), then the report
    * keeps only these lines and drops every file without one; `null`
    * measures every line.
    *
    * @var null|array<string, array<int, true>>
    */
   public null|array $changes = null;
   /**
    * Show per-file coverage diff blocks in report formatters that support it.
    */
   public bool $diff = false;

   // * Metadata
   /**
    * Changed PHP sources in the report's scope that the driver cannot
    * measure (paths the Native driver never instruments) — filled by
    * `stop()` when `$changes` is set. A gate must not read them as
    * unchanged.
    *
    * @var array<int,string>
    */
   public private(set) array $unmeasured = [];
   /**
    * Filtered hit map produced by `stop()`.
    *
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
      $unmeasured = [];
      foreach ($raw as $file => $lines) {
         $canonical = self::normalize($file);

         if (self::accept($canonical, $includes, $targets, $matchedTarget) === false) {
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

      // @ Sources no test loaded join at zero hits
      foreach ($this->paths as $path) {
         $this->expand($path, $data, $includes, $targets, $matchedTarget);
      }

      // @ Restrict to the changed lines
      if ($this->changes !== null) {
         $changed = [];
         foreach ($this->changes as $file => $lines) {
            $changed[self::normalize($file)] = $lines;
         }

         // ? A changed source no test loaded is uncovered, not unchanged:
         //   it joins at zero hits instead of vanishing from the gate
         foreach ($changed as $file => $_) {
            if (str_ends_with($file, '.php') === false) {
               continue;
            }
            if (self::resource(self::relate($file))) {
               continue;
            }

            // ? In scope but out of the driver's reach: say so, never drop it
            if (
               isset($data[$file]) === false
               && $this->Driver instanceof Native
               && Native::allow($file) === false
               && self::accept($file, $includes, $targets, $matchedTarget)
            ) {
               $unmeasured[] = $file;
               continue;
            }

            $this->admit($file, $data, $includes, $targets, $matchedTarget);
         }

         foreach ($data as $file => $lines) {
            $kept = array_intersect_key($lines, $changed[$file] ?? []);
            if ($kept === []) {
               unset($data[$file]);
               continue;
            }

            $data[$file] = $kept;
         }
      }

      ksort($data);
      foreach ($data as &$lines) {
         ksort($lines);
      }
      unset($lines);

      $this->data = $data;
      $this->unmeasured = $unmeasured;
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
    * Auto-detect the best available extension driver: Xdebug (coverage
    * mode), then PCOV, else `Nothing`.
    *
    * The Native driver is opt-in (`--coverage-driver=native`) and never
    * auto-detected: it must start before the sources it measures load and
    * needs `opcache.enable_cli=0`, so a silent pick would report a partial
    * run. A caller that gets `Nothing` back measured nothing and must say
    * so instead of writing a report.
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
    * Tell whether a canonical source belongs in the report.
    *
    * @param array<string> $includes
    * @param array<string,bool> $targets
    */
   private static function accept (string $canonical, array $includes, array $targets, bool $matched): bool
   {
      // ? Skip test scripts (e.g. Bootgly/ABI/Code/__Array/tests/1.x.Test.php).
      //   The test-framework source lives under uppercase /Tests/, so this
      //   case-sensitive check does NOT exclude those files. It reads the
      //   path below the working directory: a checkout that itself sits in
      //   a `tests/` directory (a CI workspace named so) keeps its sources.
      $relative = self::relate($canonical);
      if (str_contains("/{$relative}", '/tests/')) {
         return false;
      }

      // ? If an include scope is set, only keep files within that scope.
      if ($includes !== []) {
         $match = false;
         foreach ($includes as $include) {
            if (str_contains($canonical, $include)) {
               $match = true;
               break;
            }
         }
         if ($match === false) {
            return false;
         }
      }

      // ?: If exact SUT targets are set, keep only matched files.
      return ! ($matched && $targets !== [] && ! isset($targets[$canonical]));
   }

   /**
    * Add one PHP source the hit map lacks, with its statically analyzed
    * executable lines at zero hits.
    *
    * @param array<string, array<int, int>> $data
    * @param array<string> $includes
    * @param array<string,bool> $targets
    */
   private function admit (string $canonical, array &$data, array $includes, array $targets, bool $matched): void
   {
      // ?
      if (isset($data[$canonical])) {
         return;
      }
      if (self::accept($canonical, $includes, $targets, $matched) === false) {
         return;
      }
      // ? Native never instruments its excluded paths: they would read as
      //   unreached even when they ran
      if ($this->Driver instanceof Native && Native::allow($canonical) === false) {
         return;
      }
      if (is_file($canonical) === false || is_readable($canonical) === false) {
         return;
      }

      $source = file_get_contents($canonical);
      if ($source === false) {
         return;
      }

      // @
      $lines = Analyzer::scan($source);
      if ($lines === []) {
         return;
      }

      ksort($lines);
      $data[$canonical] = $lines;
   }

   /**
    * Add the PHP sources under one directory that the hit map lacks, each
    * with its statically analyzed executable lines at zero hits.
    *
    * Sources inside a resource directory below the path (see `RESOURCES`)
    * are not code to cover and stay out.
    * Subdirectories that cannot be read are skipped.
    *
    * @param array<string, array<int, int>> $data
    * @param array<string> $includes
    * @param array<string,bool> $targets
    */
   private function expand (string $path, array &$data, array $includes, array $targets, bool $matched): void
   {
      // ?
      $directory = realpath($path);
      if ($directory === false || is_dir($directory) === false || is_readable($directory) === false) {
         throw new LogicException("Coverage path is not a readable directory: {$path}");
      }
      $root = rtrim(self::normalize($directory), '/');

      // !
      $Files = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
         RecursiveIteratorIterator::LEAVES_ONLY,
         RecursiveIteratorIterator::CATCH_GET_CHILD
      );

      // @@
      foreach ($Files as $File) {
         /** @var SplFileInfo $File */
         if ($File->isFile() === false || $File->getExtension() !== 'php') {
            continue;
         }

         $canonical = self::normalize($File->getPathname());

         if (self::resource(substr($canonical, strlen($root) + 1))) {
            continue;
         }

         $this->admit($canonical, $data, $includes, $targets, $matched);
      }
   }

   /**
    * Give a canonical source path relative to the working directory, or
    * unchanged when it lies outside it.
    */
   private static function relate (string $canonical): string
   {
      // !
      $working = rtrim(self::normalize(BOOTGLY_WORKING_DIR), '/');

      // :
      return str_starts_with($canonical, "{$working}/")
         ? substr($canonical, strlen($working) + 1)
         : $canonical;
   }

   /**
    * Tell whether a relative source path sits inside a resource directory
    * (one of `RESOURCES`), whose files are not code to cover.
    */
   private static function resource (string $relative): bool
   {
      // !
      $segments = explode('/', $relative);
      array_pop($segments);

      // @@
      foreach ($segments as $segment) {
         if (isset(self::RESOURCES[$segment])) {
            return true;
         }
      }

      // :
      return false;
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
