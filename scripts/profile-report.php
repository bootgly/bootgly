<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework — Profile report aggregator
 * --------------------------------------------------------------------------
 *
 * Reads all per-worker `worker-*.collapsed` files written by
 * projects/Benchmark/HTTP_Server_CLI/Profiler.php and prints a single
 * merged hot-path report sorted by self-sample count.
 *
 * Usage:  php scripts/profile-report.php [--top=N] [--include=substr] [--full]
 *
 *   --top=N        Limit to top N hottest self-time functions (default 50)
 *   --include=str  Filter functions whose qualified name contains `str`
 *   --full         Include all functions (no truncation, no top limit)
 *
 * Output columns:
 *   SELF%   percentage of total samples spent self-time in this function
 *   SELF    raw self sample count
 *   INCL    inclusive (self + callees) sample count
 *   FILE:LINE  best-effort source location from the deepest stack frame
 */

declare(strict_types=1);


$opts = getopt('', ['top::', 'include::', 'full']);
$top = isset($opts['top']) ? (int) $opts['top'] : 50;
$include = isset($opts['include']) ? (string) $opts['include'] : '';
$full = isset($opts['full']);

$dir = __DIR__ . '/../workdata/temp/profile';
if (! is_dir($dir)) {
   fwrite(STDERR, "No profile dir at: $dir\n");
   exit(1);
}

$files = glob("$dir/worker-*.collapsed") ?: [];
if ($files === []) {
   fwrite(STDERR, "No worker-*.collapsed files in $dir\n");
   fwrite(STDERR, "Run benchmark with BOOTGLY_PROFILE=1 first.\n");
   exit(1);
}

// @ Parse collapsed stacks: each line = "frame1;frame2;...;leafFunc count"
//   self sample count = count of lines where leaf == function
//   inclusive = count of lines where function appears anywhere in stack
$selfByFunc = [];   // [func => count]
$inclByFunc = [];   // [func => count]
$workerSamples = [];

foreach ($files as $file) {
   $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
   if (preg_match('#worker-(\d+)\.collapsed#', $file, $m)) {
      $workerSamples[(int) $m[1]] = 0;
   }
   foreach ($lines as $line) {
      // collapsed format: "stack;frames;here count"
      $pos = strrpos($line, ' ');
      if ($pos === false) continue;
      $stack = substr($line, 0, $pos);
      $count = (int) substr($line, $pos + 1);
      if ($count <= 0) continue;

      $frames = explode(';', $stack);
      $leaf = $frames[count($frames) - 1];

      $selfByFunc[$leaf] = ($selfByFunc[$leaf] ?? 0) + $count;

      // Inclusive: each unique frame in this stack gets += count
      $seen = [];
      foreach ($frames as $f) {
         if (isset($seen[$f])) continue;
         $seen[$f] = true;
         $inclByFunc[$f] = ($inclByFunc[$f] ?? 0) + $count;
      }

      if (isset($m[1])) {
         $workerSamples[(int) $m[1]] += $count;
      }
   }
}

$totalSelf = array_sum($selfByFunc);
$totalWorkers = count($workerSamples);
$totalSamples = array_sum($workerSamples);

// @ Build rows
$rows = [];
foreach ($selfByFunc as $func => $self) {
   if ($include !== '' && ! str_contains($func, $include)) {
      continue;
   }
   $rows[] = [
      'func' => $func,
      'self' => $self,
      'incl' => $inclByFunc[$func] ?? $self,
      'pct'  => $totalSelf > 0 ? round($self / $totalSelf * 100, 2) : 0.0,
   ];
}

usort($rows, fn ($a, $b) => $b['self'] <=> $a['self']);

if (! $full) {
   $rows = array_slice($rows, 0, $top);
}

// @ Render
echo "═══════════════════════════════════════════════════════════════════════════════════════════════════\n";
echo " Bootgly hot-path profile report\n";
echo "═══════════════════════════════════════════════════════════════════════════════════════════════════\n";
echo " Workers profiled : $totalWorkers\n";
echo " Total samples    : $totalSamples\n";
echo " Filter           : " . ($include ?: '(none)') . "\n";
echo " Rows shown       : " . count($rows) . ($full ? ' (full)' : " of $top max") . "\n";
echo "───────────────────────────────────────────────────────────────────────────────────────────────────\n";
echo str_pad('SELF%', 8) . str_pad('SELF', 10) . str_pad('INCL', 10) . "FUNCTION\n";
echo str_repeat('─', 100) . "\n";

foreach ($rows as $row) {
   $func = $row['func'];
   if (strlen($func) > 75) {
      $func = '…' . substr($func, -74);
   }
   echo str_pad($row['pct'] . '%', 8)
      . str_pad((string) $row['self'], 10)
      . str_pad((string) $row['incl'], 10)
      . $func . "\n";
}

echo "───────────────────────────────────────────────────────────────────────────────────────────────────\n";
echo " Per-worker sample counts:\n";
foreach ($workerSamples as $pid => $count) {
   echo "   worker $pid : $count samples\n";
}
echo "═══════════════════════════════════════════════════════════════════════════════════════════════════\n";
