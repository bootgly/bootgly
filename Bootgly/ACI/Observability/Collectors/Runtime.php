<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Observability\Collectors;


use function count;
use function function_exists;
use function gc_status;
use function get_included_files;
use function getmypid;
use function is_array;
use function is_float;
use function is_int;
use function opcache_get_status;

use Bootgly\ACI\Observability\Collector;
use Bootgly\ACI\Observability\Data\Types;


class Runtime extends Collector
{
   // * Metadata
   private string $pid;


   /**
    * Capture the PID used to label runtime series.
    */
   public function __construct ()
   {
      // * Metadata
      $pid = getmypid();
      $this->pid = $pid === false ? '0' : (string) $pid;
   }

   /**
    * Collect PHP runtime health: garbage collector, included files and OPcache (when enabled).
    *
    * @return array<string, array{type: string, help: string, series: list<array<string, mixed>>}>
    */
   public function collect (): array
   {
      $labels = ['pid' => $this->pid];
      $metrics = [];

      // # Garbage collector
      $gc = gc_status();
      $metrics['runtime_gc_runs_total'] =
         $this->compose(Types::Counter, 'Total garbage collector cycles run.', $labels, (float) $gc['runs']);
      $metrics['runtime_gc_collected_total'] =
         $this->compose(Types::Counter, 'Total objects collected by the garbage collector.', $labels, (float) $gc['collected']);

      // # Included files
      $metrics['runtime_included_files'] =
         $this->compose(Types::Gauge, 'Number of included/required files.', $labels, (float) count(get_included_files()));

      // # OPcache (only when the extension is enabled)
      if ( function_exists('opcache_get_status') ) {
         $status = @opcache_get_status(false);
         if ( is_array($status) ) {
            // Used memory
            $memory = $status['memory_usage'] ?? null;
            if ( is_array($memory) ) {
               $used = $memory['used_memory'] ?? null;
               if ( is_int($used) || is_float($used) ) {
                  $metrics['runtime_opcache_memory_used_bytes'] =
                     $this->compose(Types::Gauge, 'OPcache used memory in bytes.', $labels, (float) $used);
               }
            }
            // Hit rate
            $stats = $status['opcache_statistics'] ?? null;
            if ( is_array($stats) ) {
               $rate = $stats['opcache_hit_rate'] ?? null;
               if ( is_int($rate) || is_float($rate) ) {
                  $metrics['runtime_opcache_hit_rate'] =
                     $this->compose(Types::Gauge, 'OPcache hit rate (percent).', $labels, (float) $rate);
               }
            }
         }
      }

      // :
      return $metrics;
   }
}
