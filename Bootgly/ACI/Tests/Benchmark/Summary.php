<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Benchmark;


use const BOOTGLY_STORAGE_DIR;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PHP_INT_MAX;
use const STR_PAD_RIGHT;
use function array_key_exists;
use function array_keys;
use function array_map;
use function bin2hex;
use function count;
use function date;
use function explode;
use function getmypid;
use function gmdate;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function json_encode;
use function ksort;
use function max;
use function microtime;
use function number_format;
use function preg_replace;
use function random_bytes;
use function sprintf;
use function str_ends_with;
use function str_pad;
use function str_repeat;
use function str_replace;
use function strlen;
use function trim;
use function uasort;
use function ucwords;
use stdClass;

use Bootgly\ABI\Data\__String\Bytes;
use Bootgly\ABI\Data\__String\Escapeable\Text\Formattable;
use Bootgly\ACI\Tests\Benchmark\Configs\Options;


class Summary
{
   use Formattable;


   /**
    * Print the complete benchmark configuration banner.
    *
    * Case options come directly from the parsed Options object. This keeps the
    * banner independent from mutable per-round Runner state and makes fixed and
    * swept server-worker values equally explicit.
    */
   public static function banner (
      Info $Info,
      Runner $Runner,
      Configs $Configs,
      string $caseName = '',
      null|Options $Options = null,
      string $style = 'full',
   ): void
   {
      $CYAN_BOLD = self::wrap(self::_CYAN_BOLD);
      $DIM       = self::wrap(self::_DIM_STYLE);
      $RESET     = self::_RESET_FORMAT;

      echo "\n{$CYAN_BOLD}  BOOTGLY BENCHMARK{$RESET}\n";
      echo "{$DIM}  " . str_repeat('─', 62) . "{$RESET}\n";

      // # Host — stable execution context comes first.
      self::write('Host', [
         'Platform' => $Info->os,
         'CPU' => "{$Info->cpuModel} ({$Info->cpuCount} cores)",
         'Memory' => $Info->ram,
         'Storage' => $Info->storage,
         'Network' => $Info->network,
         'Started' => $Info->date,
      ]);

      // # Case — resolved values, not guesses based on host CPU count.
      $caseItems = [
         'Name' => $caseName !== '' ? $caseName : 'unknown',
         'Rounds' => $Options !== null && count($Options->rounds) > 1
            ? count($Options->rounds) . ' (sweep)'
            : '1',
      ];

      $serverWorkers = null;
      if ($Options !== null) {
         foreach (array_keys($Options->schema) as $name) {
            if (isset($Options->sweeps[$name])) {
               $value = self::describe($Options->sweeps[$name]);
            }
            else if (array_key_exists($name, $Options->values)) {
               $value = self::present($Options->values[$name]);
            }
            else {
               $value = $Options->schema[$name]['type'] === 'bool'
                  ? 'disabled'
                  : 'auto';
            }

            if ($name === 'server-workers') {
               $serverWorkers = $value;
               continue;
            }

            $caseItems[ucwords(str_replace('-', ' ', $name))] = $value;
         }
      }
      // # Optional case-local harness details. A Code case may provide no
      //   subgroups; server cases can provide Client/Server, Dependencies, etc.
      $sections = $Runner->banner($Configs);
      if ($serverWorkers !== null) {
         $server = $sections['Server'] ?? [];
         unset($server['Server workers']);
         $sections['Server'] = [
            'Server workers' => $serverWorkers,
            ...$server,
         ];
      }
      foreach ($sections as $sectionName => $items) {
         if ($items === []) {
            continue;
         }
         $caseItems[$sectionName] = $items;
      }
      self::write('Case', $caseItems);

      // # Runner — global execution/output configuration.
      self::write('Runner', [
         'Name' => $Runner->name !== '' ? $Runner->name : ($Configs->runner ?? 'default'),
         'Output style' => $style,
         'Format' => $Configs->format,
         'Artifacts' => $Configs->results,
      ]);

      // # Concrete global selections. Keep the legacy banner-only call
      //   contract when no Options context was supplied by an older caller.
      if ($Options !== null) {
         self::summary($Runner, $Configs, separate: false);
      }

      self::separate();
   }

   /**
    * Render one compact, consistently aligned banner section.
    *
    * @param array<string,string|array<string,string>> $items
    */
   private static function write (string $title, array $items): void
   {
      $BOLD  = self::wrap(self::_BOLD_STYLE);
      $CYAN  = self::wrap(self::_CYAN_FOREGROUND);
      $DIM   = self::wrap(self::_DIM_STYLE);
      $RESET = self::_RESET_FORMAT;

      echo "\n{$CYAN}{$BOLD}  {$title}{$RESET}\n";

      $position = 0;
      $total = count($items);
      foreach ($items as $label => $value) {
         $position++;
         $branch = $position === $total ? '└─' : '├─';

         if (is_array($value)) {
            echo "{$DIM}  {$branch} {$label}{$RESET}\n";

            $childPosition = 0;
            $childTotal = count($value);
            $childWidth = max(
               15,
               ...array_map(
                  static fn (string $label): int => strlen($label) + 1,
                  array_keys($value),
               ),
            );
            $stem = $position === $total ? '   ' : '│  ';
            foreach ($value as $childLabel => $childValue) {
               $childPosition++;
               $childBranch = $childPosition === $childTotal ? '└─' : '├─';

               echo "{$DIM}  {$stem}{$childBranch} " . str_pad($childLabel, $childWidth)
                  . "{$RESET}{$childValue}\n";
            }
            continue;
         }

         echo "{$DIM}  {$branch} " . str_pad($label, 18) . "{$RESET}{$value}\n";
      }
   }

   /**
    * Describe a sweep without allowing a long series to dominate the banner.
    *
    * @param array<int,int> $values
    */
   private static function describe (array $values): string
   {
      $total = count($values);
      if ($total <= 6) {
         return implode(', ', $values) . " ({$total} values)";
      }

      return "{$values[0]}, {$values[1]}, …, {$values[$total - 2]}, {$values[$total - 1]}"
         . " ({$total} values)";
   }

   /**
    * Present a scalar option value consistently.
    */
   private static function present (bool|float|int|string $value): string
   {
      if (is_bool($value)) {
         return $value ? 'enabled' : 'disabled';
      }

      return (string) $value;
   }
   public static function separate (): void
   {
      echo "\n  " . self::wrap(self::_DIM_STYLE) . str_repeat('─', 62) . self::_RESET_FORMAT . "\n\n";
   }

   /**
    * Print opponents and loads summary.
    *
    * @param Runner $Runner
    * @param Configs $Configs
    */
   public static function summary (Runner $Runner, Configs $Configs, bool $separate = true): void
   {
      $BOLD  = self::wrap(self::_BOLD_STYLE);
      $DIM   = self::wrap(self::_DIM_STYLE);
      $RESET = self::_RESET_FORMAT;

      // # Opponents
      $opponentItems = [];
      $isFirst = true;
      foreach ($Runner->opponents as $Opponent) {
         if ($Configs->opponents !== null && !in_array(Configs::slug($Opponent->name), array_map(Configs::slug(...), $Configs->opponents))) {
            continue;
         }

         $version = $Opponent->version !== '' ? " {$Opponent->version}" : '';
         $baseline = $isFirst ? "{$DIM}  [baseline]{$RESET}" : '';

         $opponentItems['#' . (count($opponentItems) + 1)] = "{$BOLD}{$Opponent->name}{$RESET}"
            . $version . $baseline;

         $isFirst = false;
      }
      $opponentItems = [
         'Selection' => count($opponentItems) . '/' . count($Runner->opponents) . ' opponents',
         ...$opponentItems,
      ];
      self::write('Opponents', $opponentItems);

      // # Loads
      if ($Runner->loads !== []) {
         $Loads = $Runner->loads;

         // ? Apply load filter
         $FilteredLoads = [];
         foreach ($Loads as $index => $Load) {
            if ($Configs->loads !== null && !in_array($index + 1, $Configs->loads)) {
               continue;
            }
            $FilteredLoads[$index] = $Load;
         }

         $loadItems = [
            'Load set' => $Configs->loadSet ?? 'not selected',
            'Selection' => count($FilteredLoads) . '/' . count($Loads) . ' loads',
         ];
         foreach ($FilteredLoads as $index => $Load) {
            $group = $Load->group !== '' ? "{$DIM}{$Load->group} · {$RESET}" : '';
            $loadItems['#' . ($index + 1)] = $group . $Load->label;
         }
         self::write('Loads', $loadItems);
      }

      if ($separate) {
         self::separate();
      }
   }

   /**
    * Print results table (auto-detects code vs server).
    *
    * @param array<string,array<string,Result>> $results
    * @param bool $compact Skip the leading separator and headline (sweep rounds).
    */
   public static function report (array $results, string $metric = 'req/s', bool $compact = false): void
   {
      $allOpponents = array_keys($results);

      if (count($allOpponents) === 0) {
         return;
      }

      $BOLD    = self::wrap(self::_BOLD_STYLE);
      $DIM     = self::wrap(self::_DIM_STYLE);
      $GREEN   = self::wrap(self::_GREEN_FOREGROUND);
      $RED     = self::wrap(self::_RED_FOREGROUND);
      $YELLOW  = self::wrap(self::_YELLOW_FOREGROUND);
      $CYAN    = self::wrap(self::_CYAN_FOREGROUND);
      $RESET   = self::_RESET_FORMAT;

      // ? Compact style (sweep rounds) skips the separator + headline
      if ($compact === false) {
         self::separate();

         // @ Results header
         echo "{$BOLD}  Results\n{$RESET}\n";
         echo "  {$CYAN}" . implode(' vs ', $allOpponents) . "{$RESET}\n\n";
      }

      // @ Detect type — a server benchmark if ANY result carries throughput
      //   (rps). Inspecting only the first opponent's first load misclassifies
      //   the whole run when the baseline opponent failed preflight (rps null),
      //   blanking every opponent's real numbers under the code-benchmark table.
      $isServer = false;
      foreach ($results as $loads) {
         foreach ($loads as $Result) {
            if (
               $Result->rps !== null
               || $Result->accounting !== null
               || $Result->responses !== null
            ) {
               $isServer = true;
               break 2;
            }
         }
      }

      if ($isServer) {
         $opponents = array_keys($results);

         if (count($opponents) === 0) {
            return;
         }

         // @ Collect all loads
         $allLoads = [];
         foreach ($results as $loads) {
            foreach (array_keys($loads) as $label) {
               if (!in_array($label, $allLoads)) {
                  $allLoads[] = $label;
               }
            }
         }

         // # Column widths
         $loadWidth = 20;
         foreach ($allLoads as $label) {
            $loadWidth = max($loadWidth, strlen($label) + 2);
         }

         // ? Solo mode (single opponent)
         if (count($opponents) === 1) {
            $name = $opponents[0];
            $rpsWidth = max(20, strlen($metric) + 15);

            echo "{$BOLD}  {$CYAN}{$name}{$RESET}\n";

            $header = str_pad('Load', $loadWidth, ' ', STR_PAD_RIGHT)
               . str_pad('Metric', $rpsWidth, ' ', STR_PAD_RIGHT)
               . str_pad('Latency', 16, ' ', STR_PAD_RIGHT)
               . 'Transfer';

            echo "{$BOLD}  {$header}{$RESET}\n";
            echo "  " . str_repeat('─', strlen($header) + 4) . "\n";

            foreach ($allLoads as $label) {
               $Result = $results[$name][$label] ?? new Result;

               $rps = $Result->rps !== null
                  ? number_format((int) $Result->rps) . ' ' . $metric
                  : 'N/A';
               $latency = $Result->latency ?? 'N/A';
               $transfer = $Result->transfer ?? 'N/A';

               echo "  "
                  . str_pad($label, $loadWidth, ' ', STR_PAD_RIGHT)
                  . str_pad($rps, $rpsWidth, ' ', STR_PAD_RIGHT)
                  . str_pad($latency, 16, ' ', STR_PAD_RIGHT)
                  . $transfer . "\n";
            }

            echo "\n";
            return;
         }

         // # Comparative mode (first opponent = baseline)
         $baseline = $opponents[0];

         foreach ($allLoads as $label) {
            echo self::wrap(self::_MAGENTA_BOLD) . "  ── {$label} ──{$RESET}\n";

            $rpsWidth = max(20, strlen($metric) + 15);

            $header = str_pad('Opponent', 20, ' ', STR_PAD_RIGHT)
               . str_pad('Metric', $rpsWidth, ' ', STR_PAD_RIGHT)
               . str_pad('Latency', 16, ' ', STR_PAD_RIGHT)
               . str_pad('Transfer', 16, ' ', STR_PAD_RIGHT)
               . 'vs ' . $baseline;

            echo "{$BOLD}  {$header}{$RESET}\n";
            echo "  " . str_repeat('─', strlen($header) + 4) . "\n";

            $baselineRps = $results[$baseline][$label]->rps ?? 0;

            foreach ($opponents as $name) {
               $Result = $results[$name][$label] ?? new Result;

               $rps = $Result->rps !== null
                  ? number_format((int) $Result->rps) . ' ' . $metric
                  : 'N/A';
               $latency = $Result->latency ?? 'N/A';
               $transfer = $Result->transfer ?? 'N/A';

               // @ Diff% vs baseline
               $diff = '';
               if ($name === $baseline) {
                  $diff = "{$DIM}baseline{$RESET}";
               } elseif ($baselineRps > 0 && $Result->rps !== null) {
                  $pct = (($Result->rps - $baselineRps) / $baselineRps) * 100;
                  $sign = $pct >= 0 ? '+' : '';
                  // Positive = opponent faster than baseline = red (bad for baseline)
                  $diffColor = $pct >= 0 ? $RED : $GREEN;
                  $diff = $diffColor . sprintf('%s%.1f%%', $sign, $pct) . $RESET;
               }

               echo "  "
                  . str_pad($name, 20, ' ', STR_PAD_RIGHT)
                  . str_pad($rps, $rpsWidth, ' ', STR_PAD_RIGHT)
                  . str_pad($latency, 16, ' ', STR_PAD_RIGHT)
                  . str_pad($transfer, 16, ' ', STR_PAD_RIGHT)
                  . $diff . "\n";
            }

            echo "\n";
         }
      }
      else {
         $opponents = array_keys($results);

         // @ Collect all operation labels (insertion order)
         $allLabels = [];
         foreach ($results as $loads) {
            foreach (array_keys($loads) as $label) {
               if ( !in_array($label, $allLabels, true) ) {
                  $allLabels[] = $label;
               }
            }
         }

         // @ Column width (opponent name)
         $nameWidth = 12;
         foreach ($opponents as $name) {
            $nameWidth = max($nameWidth, strlen((string) $name) + 2);
         }

         // ?: Single-result table (one row per opponent) — back-compat (Template_Engine, Progress_Bar)
         if ($allLabels === ['default']) {
            self::rank($results, 'default', $nameWidth);
         }
         // @ Matrix: one table per operation label, opponents compared per operation
         else {
            foreach ($allLabels as $label) {
               echo self::wrap(self::_MAGENTA_BOLD) . "  ── {$label} ──{$RESET}\n";
               self::rank($results, $label, $nameWidth);
            }
         }
      }
   }

   /**
    * Print a time/memory table for one operation label, opponents ranked fastest-first.
    *
    * @param array<string,array<string,Result>> $results
    */
   private static function rank (array $results, string $label, int $nameWidth): void
   {
      $BOLD   = self::wrap(self::_BOLD_STYLE);
      $GREEN  = self::wrap(self::_GREEN_FOREGROUND);
      $YELLOW = self::wrap(self::_YELLOW_FOREGROUND);
      $RESET  = self::_RESET_FORMAT;

      // @ Header
      $header = str_pad('Opponent', $nameWidth, ' ', STR_PAD_RIGHT)
         . str_pad('Time', 16, ' ', STR_PAD_RIGHT)
         . str_pad('Memory', 16, ' ', STR_PAD_RIGHT)
         . 'Position';

      echo "{$BOLD}  {$header}{$RESET}\n";
      echo "  " . str_repeat('─', strlen($header) + 4) . "\n";

      // @ Sort by time (ascending)
      $sorted = [];
      foreach ($results as $name => $loads) {
         $sorted[$name] = $loads[$label] ?? new Result;
      }
      uasort($sorted, function (Result $a, Result $b) {
         return ((float) ($a->time ?? PHP_INT_MAX)) <=> ((float) ($b->time ?? PHP_INT_MAX));
      });

      // # Rows
      $position = 1;
      $medals = ['🥇', '🥈', '🥉'];

      foreach ($sorted as $name => $Result) {
         $time = $Result->time !== null ? $Result->time . 's' : 'N/A';
         $memory = $Result->memory !== null ? Bytes::format((int) $Result->memory, 2) : 'N/A';
         $medal = $medals[$position - 1] ?? sprintf('#%d', $position);

         $color = match (true) {
            $position === 1 => $GREEN,
            $position === 2 => $YELLOW,
            default => $RESET,
         };

         echo $color . "  "
            . str_pad((string) $name, $nameWidth, ' ', STR_PAD_RIGHT)
            . str_pad($time, 16, ' ', STR_PAD_RIGHT)
            . str_pad($memory, 16, ' ', STR_PAD_RIGHT)
            . $medal
            . $RESET . "\n";

         $position++;
      }

      echo "\n";
   }

   /**
    * Save results to .marks file.
    *
    * @param string $caseName
    * @param array<string,array<string,Result>> $results
    * @param array<string,scalar|array<int,scalar>> $config Run configuration metadata
    *        (server-workers, client-workers, connections, duration, ...) emitted
    *        in the file header so trend tooling can reconstruct the X axis from
    *        a range of .marks files alone.
    * @param string $suffix Filename label inserted before `_bench.marks`
    *        (e.g. `r01` per sweep round).
    * @param Artifacts|null $Artifacts Invocation-owned workspace. The legacy
    *        fallback remains collision-resistant and atomic for direct callers.
    *
    * @return string The saved file path, relative to the working directory.
    */
   public static function save (
      string $caseName,
      array $results,
      array $config = [],
      string $suffix = '',
      null|Artifacts $Artifacts = null,
   ): string
   {
      $artifactLabel = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $suffix), '-.');
      $artifactLabel = $artifactLabel !== '' ? $artifactLabel : 'result';

      $lines = [];
      $lines[] = "# Benchmark: {$caseName}";
      $lines[] = "# Date: " . date('Y-m-d H:i:s');
      if ($Artifacts !== null) {
         // ! Invocation identity is descriptive metadata, not an experimental
         //   configuration variable. Keeping it outside Config prevents trend
         //   tooling from selecting a unique run ID as a varying X axis.
         $lines[] = "# Run ID: {$Artifacts->ID}";
         $lines[] = "# Run Directory: {$Artifacts->relativeDirectory}";
      }

      // @ Emit run configuration so charting tools can reconstruct the axis
      //   without rerunning the benchmark.
      if ($config !== []) {
         ksort($config);
         $lines[] = "# Config:";

         foreach ($config as $key => $value) {
            if (is_array($value)) {
               $value = implode(',', array_map('strval', $value));
            }

            $lines[] = "#   {$key}: {$value}";
         }
      }

      $lines[] = "";

      foreach ($results as $opponent => $loads) {
         foreach ($loads as $label => $Result) {
            $line = "[{$opponent}][{$label}]";

            if ($Result->rps !== null) {
               $line .= " rps=" . number_format((int) $Result->rps);
            }
            if ($Result->latency !== null) {
               $line .= " latency={$Result->latency}";
            }
            if ($Result->time !== null) {
               $line .= " time={$Result->time}s";
            }
            if ($Result->memory !== null) {
               $line .= " memory={$Result->memory}";
            }
            if ($Result->transfer !== null) {
               $line .= " transfer={$Result->transfer}";
            }
            if ($Result->scheduled !== null) {
               $line .= " scheduled={$Result->scheduled}";
            }
            if ($Result->sent !== null) {
               $line .= " sent={$Result->sent}";
            }
            if ($Result->responses !== null) {
               $line .= " responses={$Result->responses}";
            }
            if ($Result->informational !== null) {
               $line .= " informational={$Result->informational}";
            }
            if ($Result->outstanding !== null) {
               $line .= " outstanding={$Result->outstanding}";
            }
            if ($Result->failed !== null) {
               $line .= " failed={$Result->failed}";
            }
            if ($Result->writeFailed !== null) {
               $line .= " write_failed={$Result->writeFailed}";
            }
            if ($Result->connectionFailed !== null) {
               $line .= " connection_failed={$Result->connectionFailed}";
            }
            if ($Result->partialWrites !== null) {
               $line .= " partial_writes={$Result->partialWrites}";
            }
            if ($Result->accounting !== null) {
               $line .= ' accounting=' . ($Result->accounting ? 'valid' : 'invalid');
            }
            if ($Result->statuses !== null) {
               $line .= ' statuses=' . json_encode((object) $Result->statuses);
            }
            if ($Result->failures !== null) {
               $line .= ' failures=' . json_encode((object) $Result->failures);
            }
            if ($Result->writeFailures !== null) {
               $line .= ' write_failures=' . json_encode((object) $Result->writeFailures);
            }

            $lines[] = $line;
         }
      }

      $contents = implode("\n", $lines) . "\n";

      if ($Artifacts !== null) {
         return $Artifacts->write("marks/{$artifactLabel}_bench.marks", $contents);
      }

      // ! Direct API callers still receive a collision-resistant, atomically
      //   published file even without an invocation workspace.
      $dir = BOOTGLY_STORAGE_DIR . 'tests/benchmarks/' . $caseName;
      $time = sprintf('%.6F', microtime(true));
      [$seconds, $fraction] = explode('.', $time, 2);
      $PID = getmypid();
      $stamp = gmdate('Y-m-d_His', (int) $seconds)
         . "-{$fraction}-p{$PID}-"
         . bin2hex(random_bytes(8));
      $name = "{$stamp}-{$artifactLabel}";
      $file = "{$dir}/{$name}_bench.marks";

      Artifacts::commit($file, $contents);

      // : Saved file path (relative) — the run footer (artifacts) displays it
      return "storage/tests/benchmarks/{$caseName}/{$name}_bench.marks";
   }

   /**
    * Print the sweep announcement once (after the run summary).
    *
    * @param array<string,array<int,int>> $sweeps Swept option name => expanded values.
    * @param int $rounds Total execution rounds.
    */
   public static function sweep (array $sweeps, int $rounds): void
   {
      $BOLD  = self::wrap(self::_BOLD_STYLE);
      $DIM   = self::wrap(self::_DIM_STYLE);
      $RESET = self::_RESET_FORMAT;

      echo "{$BOLD}  Sweep{$RESET}\n";

      foreach ($sweeps as $name => $values) {
         echo "{$DIM}  " . str_pad($name, 16) . implode(', ', $values) . "{$RESET}\n";
      }

      echo "{$DIM}  " . str_pad('rounds', 16) . $rounds . "{$RESET}\n";

      self::separate();
   }

   /**
    * Open a sweep round in the output — print its short header.
    *
    * @param array<string,scalar> $values Swept option values of this round.
    * @param int $index 1-based round index.
    * @param int $total Total rounds.
    */
   public static function open (array $values, int $index, int $total): void
   {
      $DIM   = self::wrap(self::_DIM_STYLE);
      $RESET = self::_RESET_FORMAT;

      $pairs = [];
      foreach ($values as $name => $value) {
         $pairs[] = "{$name}={$value}";
      }
      $header = implode(' ', $pairs);

      echo self::wrap(self::_CYAN_BOLD) . "  ── {$header} ──{$RESET}"
         . "{$DIM}  ({$index}/{$total}){$RESET}\n\n";
   }

   /**
    * Locate every generated artifact — print the run footer with the paths.
    *
    * Always printed for `--format=text` — in both `full` and `compact`
    * output styles — so the `.marks` location is never lost in the noise.
    *
    * @param array<int,string> $marks Saved `.marks` paths (one per round).
    * @param array<int,string> $generated Report/chart paths (when `--results` > marks).
    * @param string|null $directory Invocation-owned artifact directory.
    * @param string|null $pathBase Absolute base for relative artifact paths.
    */
   public static function locate (
      array $marks,
      array $generated = [],
      null|string $directory = null,
      null|string $pathBase = null,
   ): void
   {
      $BOLD  = self::wrap(self::_BOLD_STYLE);
      $DIM   = self::wrap(self::_DIM_STYLE);
      $RESET = self::_RESET_FORMAT;

      echo "\n{$BOLD}  Artifacts{$RESET}\n";

      if ($pathBase !== null) {
         echo "{$DIM}  " . str_pad('Base', 9) . "{$pathBase}{$RESET}\n";
      }
      if ($directory !== null) {
         echo "{$DIM}  " . str_pad('Run', 9) . "{$directory}{$RESET}\n";
      }

      // # Marks — one file per round
      foreach ($marks as $file) {
         echo "{$DIM}  Marks    {$file}{$RESET}\n";
      }

      // # Report + charts
      foreach ($generated as $file) {
         $label = str_ends_with($file, '/manifest.json')
            ? 'Manifest'
            : (str_ends_with($file, '.svg') ? 'Chart' : 'Report');
         echo "{$DIM}  " . str_pad($label, 8) . " {$file}{$RESET}\n";
      }

      echo "\n";
   }

   /**
    * Serialize a full run (all rounds) to a JSON document.
    *
    * The supervised `--format=json` command validates this complete document
    * and makes it the only content written to its public stdout descriptor.
    *
    * @param string $caseName
    * @param string $metric
    * @param array<string,scalar|array<int,scalar>> $config Non-swept run configuration.
    * @param array<string,array<int,int>> $sweeps Swept option name => expanded values.
    * @param array<int,array{options:array<string,scalar>,results:array<string,array<string,Result>>,marks:string}> $rounds
    * @param array<int,string> $artifacts Report/chart paths (when generated).
    * @param string|null $ID Collision-resistant invocation ID.
    * @param string|null $directory Invocation-owned artifact directory.
    * @param string|null $pathBase Absolute base for relative artifact paths.
    */
   public static function export (
      string $caseName,
      string $metric,
      array $config,
      array $sweeps,
      array $rounds,
      array $artifacts = [],
      null|string $ID = null,
      null|string $directory = null,
      null|string $pathBase = null,
   ): string
   {
      $document = [
         'run' => $ID === null
            ? new stdClass
            : ['id' => $ID, 'directory' => $directory, 'path_base' => $pathBase],
         'case' => $caseName,
         'date' => date('Y-m-d H:i:s'),
         'metric' => $metric,
         'config' => $config === [] ? new stdClass : $config,
         'sweep' => $sweeps === [] ? new stdClass : $sweeps,
         'rounds' => [],
         'artifacts' => $artifacts,
      ];

      // @@ Serialize each round
      foreach ($rounds as $round) {
         $results = [];
         foreach ($round['results'] as $opponent => $loads) {
            foreach ($loads as $label => $Result) {
               $results[$opponent][$label] = [
                  'rps' => $Result->rps,
                  'latency' => $Result->latency,
                  'transfer' => $Result->transfer,
                  'time' => $Result->time,
                  'memory' => $Result->memory,
                  'scheduled' => $Result->scheduled,
                  'sent' => $Result->sent,
                  'responses' => $Result->responses,
                  'informational' => $Result->informational,
                  'outstanding' => $Result->outstanding,
                  'failed' => $Result->failed,
                  'write_failed' => $Result->writeFailed,
                  'connection_failed' => $Result->connectionFailed,
                  'partial_writes' => $Result->partialWrites,
                  'accounting' => $Result->accounting,
                  'statuses' => $Result->statuses,
                  'failures' => $Result->failures,
                  'write_failures' => $Result->writeFailures,
               ];
            }
         }

         $document['rounds'][] = [
            'options' => $round['options'] === [] ? new stdClass : $round['options'],
            'results' => $results,
            'marks' => $round['marks'],
         ];
      }

      // : Single-line JSON document + trailing newline
      return json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
   }
}
