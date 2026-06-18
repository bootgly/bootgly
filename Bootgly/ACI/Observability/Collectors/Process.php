<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Observability\Collectors;


use function count;
use function explode;
use function file_get_contents;
use function getmypid;
use function getrusage;
use function glob;
use function is_array;
use function is_numeric;
use function memory_get_peak_usage;
use function memory_get_usage;
use function microtime;
use function strrpos;
use function substr;

use Bootgly\ACI\Observability\Collector;
use Bootgly\ACI\Observability\Data\Types;


class Process extends Collector
{
   // * Metadata
   private float $started;
   private string $pid;
   // @ Real process uptime in seconds, derived from /proc — independent of when this collector was
   //   built. Reads /proc/self/stat field 22 (starttime, USER_HZ ticks since boot) + /proc/uptime;
   //   assumes USER_HZ = 100. Falls back to "since construction" when /proc is unavailable.
   private float $uptime {
      get {
         $stat = @file_get_contents('/proc/self/stat');
         $boot = @file_get_contents('/proc/uptime');

         if ( $stat !== false && $boot !== false ) {
            // # Skip "pid (comm) " — comm may contain spaces/parens, so split after the last ')'
            $close = strrpos($stat, ')');
            if ( $close !== false ) {
               $fields = explode(' ', substr($stat, $close + 2));
               // starttime is field 22 → index 19 once "pid (comm)" is removed (state = index 0)
               $startTicks = $fields[19] ?? null;
               if ( $startTicks !== null && is_numeric($startTicks) ) {
                  $systemUptime = (float) explode(' ', $boot)[0];
                  $uptime = $systemUptime - ((float) $startTicks / 100);
                  if ( $uptime >= 0.0 ) {
                     return $uptime;
                  }
               }
            }
         }

         // : Fallback — seconds since this collector was constructed
         return microtime(true) - $this->started;
      }
   }


   /**
    * Capture the process start reference and PID for self-health sampling.
    */
   public function __construct ()
   {
      // * Metadata
      $this->started = microtime(true);

      $pid = getmypid();
      $this->pid = $pid === false ? '0' : (string) $pid;
   }

   /**
    * Collect self-process health: memory, CPU seconds, uptime and open file descriptors.
    *
    * Reads only PHP builtins and `/proc/self` (never the ACI Process class) to respect ACI layering.
    *
    * @return array<string, array{type: string, help: string, series: list<array<string, mixed>>}>
    */
   public function collect (): array
   {
      // ! Per-process series labelled by PID (keeps worker series distinct when merged)
      $labels = ['pid' => $this->pid];

      // # Memory (allocator, real usage)
      $memory = (float) memory_get_usage(true);
      $memoryPeak = (float) memory_get_peak_usage(true);

      // # CPU — user + system seconds via getrusage
      $cpu = 0.0;
      $usage = getrusage();
      if ( is_array($usage) ) {
         $cpu =
              ($usage['ru_utime.tv_sec'] ?? 0) + ($usage['ru_utime.tv_usec'] ?? 0) / 1_000_000
            + ($usage['ru_stime.tv_sec'] ?? 0) + ($usage['ru_stime.tv_usec'] ?? 0) / 1_000_000;
      }

      // # Uptime — real process uptime (not since this collector was built)
      $uptime = $this->uptime;

      // # Open file descriptors (Linux /proc)
      $fds = 0;
      $entries = glob('/proc/self/fd/*');
      if ( $entries !== false ) {
         $fds = count($entries);
      }

      // :
      return [
         'process_memory_bytes' =>
            $this->compose(Types::Gauge, 'Process memory usage in bytes (allocator, real).', $labels, $memory),
         'process_memory_peak_bytes' =>
            $this->compose(Types::Gauge, 'Process peak memory in bytes (allocator, real).', $labels, $memoryPeak),
         'process_cpu_seconds_total' =>
            $this->compose(Types::Counter, 'Total user + system CPU seconds consumed.', $labels, $cpu),
         'process_uptime_seconds' =>
            $this->compose(Types::Gauge, 'Seconds since the process started.', $labels, $uptime),
         'process_open_fds' =>
            $this->compose(Types::Gauge, 'Open file descriptors.', $labels, (float) $fds),
      ];
   }
}
