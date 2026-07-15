<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework — Profile report aggregator
 * --------------------------------------------------------------------------
 *
 * Reads the per-worker `worker-*.collapsed` files from one explicitly selected
 * benchmark run and prints a single merged hot-path report sorted by
 * self-sample count.
 *
 * Usage:  php scripts/profile-report.php --run-dir=PATH [--round=ID]
 *             [--profile-scope=NAME] [--top=N] [--include=substr] [--full]
 *
 *   --run-dir=PATH Unique benchmark run directory (required)
 *   --round=ID      Include only one round (for example, r01)
 *   --profile-scope=NAME Include only one profiler scope (for example, bootgly)
 *   --top=N        Limit to top N hottest self-time functions (default 50)
 *   --include=str  Filter functions whose qualified name contains `str`
 *   --full         Include all functions (no truncation, no top limit)
 *
 * Output columns:
 *   SELF%   percentage of total samples spent self-time in this function
 *   SELF    raw self sample count
 *   INCL    inclusive (self + callees) sample count
 */

declare(strict_types=1);


$options = getopt('', ['run-dir:', 'round:', 'profile-scope:', 'top::', 'include::', 'full']);
$options = is_array($options) ? $options : [];
$runOption = $options['run-dir'] ?? null;
if (is_string($runOption) === false || $runOption === '') {
   fwrite(STDERR, "Usage: php scripts/profile-report.php --run-dir=PATH [--round=ID] [--profile-scope=NAME] [--top=N] [--include=substr] [--full]\n");
   exit(2);
}

$runDirectory = realpath($runOption);
if ($runDirectory === false || is_dir($runDirectory) === false) {
   fwrite(STDERR, "Benchmark run directory does not exist: $runOption\n");
   exit(1);
}

$profileDirectory = realpath($runDirectory . '/profiles/server');
$runPrefix = rtrim($runDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
if (
   $profileDirectory === false
   || is_dir($profileDirectory) === false
   || str_starts_with($profileDirectory . DIRECTORY_SEPARATOR, $runPrefix) === false
) {
   fwrite(STDERR, "No run-scoped server profile directory at: $runDirectory/profiles/server\n");
   exit(1);
}

$top = isset($options['top']) ? (int) $options['top'] : 50;
$include = isset($options['include']) ? (string) $options['include'] : '';
$full = isset($options['full']);

$Normalize = static function (mixed $value, string $prefix): null|string {
   if ($value === null) {
      return null;
   }
   if (is_string($value) === false || trim($value) === '') {
      fwrite(STDERR, "Invalid empty profile selector.\n");
      exit(2);
   }

   $segment = strtolower(trim($value));
   $segment = preg_match('/\A[a-z0-9][a-z0-9_-]*\z/D', $segment) === 1
      ? $segment
      : 'encoded-' . bin2hex($segment);

   return str_starts_with($segment, $prefix . '-')
      ? $segment
      : $prefix . '-' . $segment;
};
$roundSelector = $Normalize($options['round'] ?? null, 'round');
$scopeSelector = $Normalize($options['profile-scope'] ?? null, 'scope');

$files = [];
$Children = new RecursiveDirectoryIterator(
   $profileDirectory,
   FilesystemIterator::SKIP_DOTS,
);
$Iterator = new RecursiveIteratorIterator(
   $Children,
   RecursiveIteratorIterator::LEAVES_ONLY,
);
foreach ($Iterator as $File) {
   $relative = substr($File->getPathname(), strlen($profileDirectory) + 1);
   $segments = explode(DIRECTORY_SEPARATOR, $relative);
   if (
      $File->isFile()
      && $File->isLink() === false
      && preg_match('/\Aworker-(\d+)\.collapsed\z/D', $File->getFilename()) === 1
      && ($roundSelector === null || in_array($roundSelector, $segments, true))
      && ($scopeSelector === null || in_array($scopeSelector, $segments, true))
   ) {
      $files[] = $File->getPathname();
   }
}
sort($files, SORT_STRING);

if ($files === []) {
   fwrite(STDERR, "No worker-*.collapsed files in $profileDirectory\n");
   fwrite(STDERR, "Run this benchmark invocation with BOOTGLY_PROFILE=1 first.\n");
   exit(1);
}

// @ Parse collapsed stacks: each line = "frame1;frame2;...;leafFunc count"
//   self sample count = count of lines where leaf == function
//   inclusive = count of lines where function appears anywhere in stack
$selfByFunc = [];   // [func => count]
$inclByFunc = [];   // [func => count]
$profileSamples = [];

foreach ($files as $file) {
   $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
   if ($lines === false) {
      fwrite(STDERR, "Cannot read profile artifact: $file\n");
      exit(1);
   }
   $artifact = substr($file, strlen($profileDirectory) + 1);
   $profileSamples[$artifact] = 0;
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

      $profileSamples[$artifact] += $count;
   }
}

ksort($profileSamples, SORT_STRING);

$totalSelf = array_sum($selfByFunc);
$totalProfiles = count($profileSamples);
$totalSamples = array_sum($profileSamples);

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
echo " Run directory    : $runDirectory\n";
echo " Round selector   : " . ($roundSelector ?? '(all)') . "\n";
echo " Profile scope    : " . ($scopeSelector ?? '(all)') . "\n";
echo " Profile artifacts: $totalProfiles\n";
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
echo " Per-profile sample counts:\n";
foreach ($profileSamples as $artifact => $count) {
   echo "   $artifact : $count samples\n";
}
echo "═══════════════════════════════════════════════════════════════════════════════════════════════════\n";
