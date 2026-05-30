<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace projects\HTTP_Server_CLI;


use function array_values;
use function file_put_contents;
use function getenv;
use function is_dir;
use function mkdir;
use function posix_getpid;
use function register_shutdown_function;
use function round;
use function str_pad;
use function strlen;
use function substr;
use ExcimerLog;
use ExcimerProfiler;

use const EXCIMER_CPU;
use const EXCIMER_REAL;


/**
 * Hot-path sampling profiler for HTTP_Server_CLI workers.
 *
 * Wraps PECL `excimer` (~5% overhead, kernel-signal based sampling).
 *
 * Lifecycle:
 *   - start(): idempotent per-worker init (no-op if same PID already started).
 *   - dump():  auto-invoked at worker shutdown via register_shutdown_function.
 *   - Dumps per-worker files into workdata/temp/profile/:
 *       worker-{PID}.collapsed     (Brendan Gregg collapsed format → flamegraph.pl)
 *       worker-{PID}.speedscope    (speedscope.app JSON)
 *       worker-{PID}.aggregated    (top-N self/inclusive table — human readable)
 *
 * Enable via env: `BOOTGLY_PROFILE=1`
 * Tune period via env: `BOOTGLY_PROFILE_PERIOD=0.0001` (default 100µs = 10kHz)
 * Tune event type: `BOOTGLY_PROFILE_EVENT=cpu|real` (default cpu)
 */
final class Profiler
{
   // * Data
   private static null|ExcimerProfiler $Profiler = null;
   private static int $workerPid = 0;
   private static string $outputDir = '';


   public static function start (): void
   {
      $pid = posix_getpid();

      // ? Already started in this process — idempotent
      if (self::$Profiler !== null && self::$workerPid === $pid) {
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
      self::$workerPid = $pid;
      self::$outputDir = __DIR__ . '/../../../workdata/temp/profile';

      register_shutdown_function([self::class, 'dump']);
   }

   public static function dump (): void
   {
      if (self::$Profiler === null) {
         return;
      }

      self::$Profiler->stop();
      $Log = self::$Profiler->getLog();

      $dir = self::$outputDir;
      if (! is_dir($dir)) {
         @mkdir($dir, 0777, true);
      }

      $pid = self::$workerPid;
      $base = "$dir/worker-$pid";

      // @ Brendan Gregg collapsed format → flamegraph.pl
      file_put_contents("$base.collapsed", $Log->formatCollapsed());

      // @ speedscope.app JSON
      file_put_contents("$base.speedscope.json", json_encode($Log->getSpeedscopeData()));

      // @ Human-readable top-N table
      file_put_contents("$base.aggregated.txt", self::tabulate($Log));

      self::$Profiler = null;
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

      $out = "# Excimer profile — worker PID " . self::$workerPid . "\n";
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
