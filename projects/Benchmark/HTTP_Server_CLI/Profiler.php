<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Benchmark\HTTP_Server_CLI;


use const EXCIMER_CPU;
use const EXCIMER_REAL;
use function array_slice;
use function array_values;
use function bin2hex;
use function fclose;
use function fflush;
use function fopen;
use function function_exists;
use function fsync;
use function fwrite;
use function getenv;
use function is_dir;
use function json_encode;
use function mkdir;
use function posix_getpid;
use function random_bytes;
use function register_shutdown_function;
use function rename;
use function round;
use function rtrim;
use function str_pad;
use function str_repeat;
use function str_starts_with;
use function strtolower;
use function strlen;
use function substr;
use function trim;
use function unlink;
use function usort;
use ExcimerLog;
use ExcimerProfiler;


/**
 * Hot-path sampling profiler for HTTP_Server_CLI workers.
 *
 * Wraps PECL `excimer` (~5% overhead, kernel-signal based sampling).
 *
 * Lifecycle:
 *   - start(): idempotent per-worker init (no-op if same PID already started).
 *   - dump():  auto-invoked at worker shutdown via register_shutdown_function.
 *   - Dumps per-worker files into the active run's profiles/server/ directory:
 *       worker-{PID}.collapsed     (Brendan Gregg collapsed format → flamegraph.pl)
 *       worker-{PID}.speedscope.json (speedscope.app JSON)
 *       worker-{PID}.aggregated.txt  (top-N self/inclusive table — human readable)
 *   - Falls back to storage/temp/profile/ only outside a benchmark run.
 *
 * Enable via env: `BOOTGLY_PROFILE=1`
 * Tune period via env: `BOOTGLY_PROFILE_PERIOD=0.0001` (default 100µs = 10kHz)
 * Tune event type: `BOOTGLY_PROFILE_EVENT=cpu|real` (default cpu)
 */
final class Profiler
{
   // * Data
   private static null|ExcimerProfiler $Profiler = null;
   private static int $workerPID = 0;
   private static string $outputDirectory = '';


   public static function start (): void
   {
      $PID = posix_getpid();

      // ? Already started in this process — idempotent
      if (self::$Profiler !== null && self::$workerPID === $PID) {
         return;
      }

      $period = (float) (getenv('BOOTGLY_PROFILE_PERIOD') ?: '0.0001');
      $eventName = getenv('BOOTGLY_PROFILE_EVENT') ?: 'cpu';
      $eventType = $eventName === 'real' ? EXCIMER_REAL : EXCIMER_CPU;

      $Profiler = new ExcimerProfiler;
      $Profiler->setPeriod($period);
      $Profiler->setEventType($eventType);
      $Profiler->setMaxDepth(64);
      $Profiler->start();

      self::$Profiler = $Profiler;
      self::$workerPID = $PID;
      $runDirectory = getenv('BENCHMARK_RUN_DIR');
      if ($runDirectory !== false && $runDirectory !== '') {
         self::$outputDirectory = rtrim($runDirectory, '/\\') . '/profiles/server';

         $round = getenv('BENCHMARK_ROUND');
         if ($round !== false && $round !== '') {
            $round = self::normalize($round);
            self::$outputDirectory .= str_starts_with($round, 'round-')
               ? "/$round"
               : "/round-$round";
         }

         $profileScope = getenv('BENCHMARK_PROFILE_SCOPE');
         if ($profileScope !== false && $profileScope !== '') {
            $profileScope = self::normalize($profileScope);
            self::$outputDirectory .= str_starts_with($profileScope, 'scope-')
               ? "/$profileScope"
               : "/scope-$profileScope";
         }

         $invocationDirectory = getenv('BENCHMARK_INVOCATION_DIR');
         if ($invocationDirectory !== false && $invocationDirectory !== '') {
            $invocation = self::normalize(basename(rtrim($invocationDirectory, '/\\')));
            self::$outputDirectory .= str_starts_with($invocation, 'invocation-')
               ? "/$invocation"
               : "/invocation-$invocation";
         }
      }
      else {
         self::$outputDirectory = __DIR__ . '/../../../storage/temp/profile';
      }

      register_shutdown_function([self::class, 'dump']);
   }

   public static function dump (): void
   {
      if (self::$Profiler === null) {
         return;
      }

      self::$Profiler->stop();
      $Log = self::$Profiler->getLog();

      $directory = self::$outputDirectory;
      if (
         is_dir($directory) === false
         && @mkdir($directory, 0777, true) === false
         && is_dir($directory) === false
      ) {
         fwrite(STDERR, "ERROR: Cannot create server profile directory: $directory\n");
         self::$Profiler = null;
         return;
      }

      $PID = self::$workerPID;
      $base = "$directory/worker-$PID";

      // @ Brendan Gregg collapsed format → flamegraph.pl
      $published = self::publish("$base.collapsed", $Log->formatCollapsed());

      // @ speedscope.app JSON
      $JSON = json_encode($Log->getSpeedscopeData());
      $published = $JSON !== false
         && self::publish("$base.speedscope.json", $JSON)
         && $published;

      // @ Human-readable top-N table
      $published = self::publish("$base.aggregated.txt", self::tabulate($Log))
         && $published;

      if ($published === false) {
         fwrite(STDERR, "ERROR: Cannot publish every server profile artifact at: $base\n");
      }

      self::$Profiler = null;
   }

   /**
    * Publish one complete profile through a same-directory atomic rename.
    */
   private static function publish (string $file, string $contents): bool
   {
      $Handle = false;
      $temporary = '';
      for ($attempt = 0; $attempt < 8; $attempt++) {
         $temporary = $file . '.' . bin2hex(random_bytes(16)) . '.tmp';
         $Handle = @fopen($temporary, 'x+b');
         if ($Handle !== false) {
            break;
         }
      }
      if ($Handle === false) {
         return false;
      }

      $complete = false;
      try {
         $length = strlen($contents);
         $offset = 0;
         while ($offset < $length) {
            $written = fwrite($Handle, substr($contents, $offset));
            if ($written === false || $written === 0) {
               break;
            }
            $offset += $written;
         }
         $complete = $offset === $length
            && fflush($Handle)
            && (function_exists('fsync') === false || fsync($Handle));
      }
      finally {
         fclose($Handle);
         if ($complete === false) {
            @unlink($temporary);
         }
      }

      if ($complete === false || @rename($temporary, $file) === false) {
         @unlink($temporary);
         return false;
      }

      return true;
   }

   /**
    * Normalize one environment-provided value into a safe path segment.
    */
   private static function normalize (string $segment): string
   {
      $segment = strtolower(trim($segment));
      if ($segment === '') {
         throw new \InvalidArgumentException('Invalid empty benchmark profile segment.');
      }

      return \preg_match('/\A[a-z0-9][a-z0-9_-]*\z/D', $segment) === 1
         ? $segment
         : 'encoded-' . bin2hex($segment);
   }

   private static function tabulate (ExcimerLog $Log): string
   {
      $aggregated = $Log->aggregateByFunction();
      $total = 0;
      foreach ($aggregated as $row) {
         $total += $row['self'];
      }

      // @ Sort by self time desc
      $rows = [];
      foreach ($aggregated as $func => $row) {
         $rows[] = [
            'func' => $func,
            'self' => $row['self'],
            'inclusive' => $row['inclusive'],
            'self_pct' => $total > 0 ? round($row['self'] / $total * 100, 2) : 0.0,
         ];
      }
      $selfCols = array_values($rows);
      usort($selfCols, fn ($a, $b) => $b['self'] <=> $a['self']);

      $out = "# Excimer profile — worker PID " . self::$workerPID . "\n";
      $out .= "# Total samples: $total\n";
      $out .= "# Sample period: " . (getenv('BOOTGLY_PROFILE_PERIOD') ?: '0.0001') . "s\n";
      $out .= "# Event type:    " . (getenv('BOOTGLY_PROFILE_EVENT') ?: 'cpu') . "\n";
      $out .= "\n";
      $out .= str_pad('SELF%', 8) . str_pad('SELF', 8) . str_pad('INCL', 8) . "FUNCTION\n";
      $out .= str_repeat('─', 100) . "\n";

      $top = array_slice($selfCols, 0, 60);
      foreach ($top as $row) {
         $out .= str_pad($row['self_pct'] . '%', 8)
              . str_pad((string) $row['self'], 8)
              . str_pad((string) $row['inclusive'], 8)
              . self::truncate($row['func'], 80) . "\n";
      }

      return $out;
   }

   private static function truncate (string $s, int $max): string
   {
      return strlen($s) > $max ? '…' . substr($s, -($max - 1)) : $s;
   }
}
