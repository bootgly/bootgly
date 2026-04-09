<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Benchmark;


use function date;
use function exec;
use function php_uname;
use function round;
use function trim;


class Info
{
   // * Data
   public string $os = '';
   public string $cpuModel = '';
   public string $cpuCount = '';
   public string $ram = '';
   public string $storage = '';
   public string $network = '';
   public string $date = '';


   public static function collect (): self
   {
      $Info = new self;

      // @ OS
      $Info->os = php_uname('s') . ' ' . php_uname('r');

      // @ CPU
      $cpuOutput = [];
      exec("lscpu 2>/dev/null | grep 'Model name' | head -1 | sed 's/.*:\\s*//'", $cpuOutput);
      $Info->cpuModel = trim($cpuOutput[0] ?? 'unknown');

      $Info->cpuCount = trim(exec('nproc 2>/dev/null') ?: '1');

      // @ RAM (bytes → GB with 1 decimal)
      $ramOutput = [];
      exec("free -b 2>/dev/null | awk '/Mem:/ {print \$2}'", $ramOutput);
      $ramBytes = (int) trim($ramOutput[0] ?? '0');
      $Info->ram = $ramBytes > 0
         ? round($ramBytes / 1_000_000_000, 1) . ' GB'
         : 'unknown';

      // @ Storage (root partition)
      $storageOutput = [];
      exec("df -h / 2>/dev/null | awk 'NR==2 {print \$2, \"total,\", \$4, \"avail\"}'", $storageOutput);
      $Info->storage = trim($storageOutput[0] ?? 'unknown');

      // @ Network (primary interface + speed)
      $ifaceOutput = [];
      exec("ip route 2>/dev/null | awk '/default/ {print \$5; exit}'", $ifaceOutput);
      $iface = trim($ifaceOutput[0] ?? '');
      if ($iface !== '') {
         $speedOutput = [];
         exec("ethtool {$iface} 2>/dev/null | awk -F: '/Speed/ {gsub(/^[ \\t]+/,\"\",\$2); print \$2}'", $speedOutput);
         $speed = trim($speedOutput[0] ?? '');
         $Info->network = $speed !== '' ? "{$iface} ({$speed})" : $iface;
      } else {
         $Info->network = 'unknown';
      }

      // @ Date
      $Info->date = date('Y-m-d H:i:s');

      return $Info;
   }
}
