<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Coverage\Drivers;


use function array_pop;
use function in_array;
use function ini_get;
use function str_contains;
use function str_replace;
use function stream_filter_register;
use LogicException;

use Bootgly\ACI\Tests\Coverage;
use Bootgly\ACI\Tests\Coverage\Driver;
use Bootgly\ACI\Tests\Coverage\Drivers\Native\Filter;
use Bootgly\ACI\Tests\Coverage\Drivers\Native\Universe;


/**
 * Pure-PHP coverage driver.
 *
 * Strategy: instrument PHP source files at autoload time using a
 * stream filter (`bootgly.coverage`) that injects line-hit markers via
 * `Bootgly\ACI\Tests\Coverage::hit()`. The denominator is collected
 * incrementally by the `Compiler` and stored in `Universe`.
 *
 * Limitations:
 *   - Only files included **after** `start()` are instrumented.
 *   - OPcache CLI must be disabled (it caches uninstrumented bytecode).
 *   - Aggregation across forked workers is out of scope.
 *
 * Usage: requires explicit selection (`--coverage-driver=native`)
 * until self-tests stabilize. Auto-detection is intentionally
 * deferred.
 */
final class Native extends Driver
{
   /**
    * Strict executable-statement instrumentation mode.
    */
   public const string MODE_STRICT = 'strict';
   /**
    * XDebug-like projection mode for declarations, labels, and spans.
    */
   public const string MODE_PARITY = 'parity';

   /**
    * On/off switch read by `autoboot.php` to decide whether to route
    * autoload includes through the coverage filter.
    */
   public static bool $active = false;
   /**
    * Active instrumentation profile read by the stream filter.
    */
   public static string $mode = self::MODE_STRICT;

   /**
    * Static include stack used by the stream filter to recover the
    * canonical file path of the source being rewritten.
    *
    * @var array<int,string>
    */
   public static array $stack = [];

   /**
    * Path fragments that must NEVER be instrumented (driver internals,
    * vendor code, etc.). Compared as forward-slash-normalized
    * substrings of the absolute path.
    *
    * @var array<int,string>
    */
   public static array $excludes = [
      '/Bootgly/ACI/Tests/Coverage/',
      '/vendor/',
      '/workdata/',
      '/tmp/',
   ];

   /**
    * Whether the user explicitly requested Native (vs auto-detect).
    */
   public bool $explicit = false;
   /**
    * Instrumentation profile selected for this driver instance.
    */
   public string $profile;

   /**
    * Whether the stream filter was registered in this PHP process.
    */
   private static bool $registered = false;


   /**
    * Create a Native coverage driver session.
    */
   public function __construct (bool $explicit = false, string $mode = self::MODE_STRICT)
   {
      $this->explicit = $explicit;

      if (! in_array($mode, [self::MODE_STRICT, self::MODE_PARITY], true)) {
         throw new LogicException("Unknown native coverage mode: {$mode}");
      }
      $this->profile = $mode;

      if ($explicit && (bool) ini_get('opcache.enable_cli')) {
         throw new LogicException(
            'Native coverage requires opcache.enable_cli=0; '
            . 'retry with: php -d opcache.enable_cli=0 bootgly test ...'
         );
      }
   }

   /**
    * Decide whether a given absolute file path should be instrumented.
    */
   public static function allow (string $file): bool
   {
      $norm = str_replace('\\', '/', $file);
      foreach (self::$excludes as $prefix) {
         if (str_contains($norm, $prefix)) {
            return false;
         }
      }
      return true;
   }

   /**
    * Route an autoload include through Native instrumentation when active.
    */
   public static function route (string $file): bool
   {
      if (self::$active === false || self::allow($file) === false) {
         return false;
      }

      self::load($file);
      return true;
   }

   /**
    * Include a PHP source file through the coverage stream filter.
    * The canonical path is pushed onto a static stack so the filter
    * can recover it without relying on php://filter user params.
    */
   public static function load (string $file): void
   {
      self::$stack[] = $file;
      try {
         include 'php://filter/read=' . Filter::NAME . '/resource=' . $file;
      }
      finally {
         array_pop(self::$stack);
      }
   }

   /**
    * Activate the stream-filter instrumentation backend.
    */
   protected function begin (): void
   {
      if ((bool) ini_get('opcache.enable_cli')) {
         throw new LogicException(
            'Native coverage requires opcache.enable_cli=0; '
            . 'retry with: php -d opcache.enable_cli=0 bootgly test ...'
         );
      }

      Coverage::reset();
      Universe::reset();

      if (! self::$registered) {
         if (! stream_filter_register(Filter::NAME, Filter::class)) {
            throw new LogicException('Native coverage failed to register stream filter: ' . Filter::NAME);
         }
         self::$registered = true;
      }

      self::$mode = $this->profile;
      self::$active = true;
   }

   /**
    * Deactivate Native instrumentation after the session finishes.
    */
   protected function end (): void
   {
      self::$active = false;
      self::$mode = self::MODE_STRICT;
   }

   /**
    * Merge Native executable-line denominators with runtime hits.
    *
    * @return array<string,array<int,int>>
    */
   public function collect (): array
   {
      $merged = Universe::merge(Coverage::$hits, $this->profile);
      // Normalize to 0/1 to match Pcov/Xdebug semantics so the
      // existing reports compute the right percentages.
      $out = [];
      foreach ($merged as $file => $lines) {
         $bucket = [];
         foreach ($lines as $line => $count) {
            $bucket[$line] = $count > 0 ? 1 : 0;
         }
         $out[$file] = $bucket;
      }
      return $out;
   }
}
