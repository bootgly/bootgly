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
use const PHP_INT_MAX;
use const STR_PAD_RIGHT;
use function array_keys;
use function array_map;
use function count;
use function date;
use function file_put_contents;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function json_encode;
use function ksort;
use function max;
use function mkdir;
use function number_format;
use function sprintf;
use function str_ends_with;
use function str_pad;
use function str_repeat;
use function strlen;
use function uasort;
use stdClass;

use Bootgly\ABI\Data\__String\Bytes;
use Bootgly\ABI\Data\__String\Escapeable\Text\Formattable;


class Summary
{
   use Formattable;


   /**
    * Print system info banner.
    *
    * @param Info $Info
    * @param Runner $Runner
    * @param Configs $Configs
    */
   public static function banner (Info $Info, Runner $Runner, Configs $Configs, string $caseName = ''): void
   {
      $BOLD    = self::wrap(self::_BOLD_STYLE);
      $DIM     = self::wrap(self::_DIM_STYLE);
      $RESET   = self::_RESET_FORMAT;

      echo self::wrap(self::_CYAN_BOLD) . "\n";
      echo "╔══════════════════════════════════════════════════════════╗\n";
      echo "║               Bootgly Benchmark Runner                   ║\n";
      echo "╚══════════════════════════════════════════════════════════╝\n";
      echo $RESET;

      if ($caseName !== '') {
         echo "  ╰─ {$BOLD}Case:{$RESET} {$DIM}{$caseName}{$RESET}\n\n";
      }

      // # System
      echo "{$BOLD}  System{$RESET}\n";
      echo $DIM;
      echo "  OS        {$Info->os}\n";
      echo "  CPU       {$Info->cpuModel} ({$Info->cpuCount} cores)\n";
      echo "  RAM       {$Info->ram}\n";
      echo "  Storage   {$Info->storage}\n";
      echo "  Network   {$Info->network}\n";
      echo "  Date      {$Info->date}\n";
      echo $RESET;

      // # Runner banner sections (Dependencies, Configuration, etc.)
      $sections = $Runner->banner($Configs);
      foreach ($sections as $sectionName => $items) {
         echo "\n{$BOLD}  {$sectionName}{$RESET}\n";
         echo $DIM;
         foreach ($items as $label => $value) {
            echo "  " . str_pad($label, 10) . $value . "\n";
         }
         echo $RESET;
      }

      self::separate();
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
   public static function summary (Runner $Runner, Configs $Configs): void
   {
      $BOLD  = self::wrap(self::_BOLD_STYLE);
      $DIM   = self::wrap(self::_DIM_STYLE);
      $CYAN  = self::wrap(self::_CYAN_FOREGROUND);
      $RESET = self::_RESET_FORMAT;

      // # Opponents
      echo "{$BOLD}  Opponents{$RESET}\n";

      $isFirst = true;
      foreach ($Runner->opponents as $Opponent) {
         if ($Configs->opponents !== null && !in_array(Configs::slug($Opponent->name), array_map(Configs::slug(...), $Configs->opponents))) {
            continue;
         }

         $version = $Opponent->version !== '' ? " {$Opponent->version}" : '';
         $baseline = $isFirst ? "{$DIM}  [baseline]{$RESET}" : '';

         echo "{$CYAN}  ▸ {$RESET}"
            . "{$BOLD}{$Opponent->name}{$RESET}"
            . $version . $baseline . "\n";

         $isFirst = false;
      }

      // # Loads
      if ($Runner->loads !== []) {
         $loads = $Runner->loads;

         // ? Apply load filter
         $filtered = [];
         foreach ($loads as $index => $Load) {
         if ($Configs->loads !== null && !in_array($index + 1, $Configs->loads)) {
               continue;
            }
            $filtered[$index] = $Load;
         }

         echo "\n{$BOLD}  Loads (" . count($filtered) . "){$RESET}\n";

         $prevGroup = '';
         foreach ($filtered as $index => $Load) {
            if ($Load->group !== '' && $Load->group !== $prevGroup) {
               echo "    {$BOLD}{$Load->group}{$RESET}\n";
               $prevGroup = $Load->group;
            }

            echo "{$DIM}      " . ($index + 1) . ". {$Load->label}{$RESET}\n";
         }
      }

      self::separate();
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
            if ($Result->rps !== null) {
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
    * @param string $suffix Filename disambiguator inserted before `_bench.marks`
    *        (e.g. `r01` per sweep round — save() timestamps are per-second).
    *
    * @return string The saved file path, relative to the working directory.
    */
   public static function save (string $caseName, array $results, array $config = [], string $suffix = ''): string
   {
      $dir = BOOTGLY_STORAGE_DIR . 'tests/benchmarks/' . $caseName;

      if ( !is_dir($dir) ) {
         mkdir($dir, 0775, true);
      }

      // ! Build the filename once — a second date() call could cross a
      //   second boundary and display a path that does not exist.
      $stamp = date('Y-m-d_His');
      $name = $suffix !== '' ? "{$stamp}-{$suffix}" : $stamp;
      $file = "$dir/{$name}_bench.marks";

      $lines = [];
      $lines[] = "# Benchmark: {$caseName}";
      $lines[] = "# Date: " . date('Y-m-d H:i:s');

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

            $lines[] = $line;
         }
      }

      file_put_contents($file, implode("\n", $lines) . "\n");

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
    */
   public static function locate (array $marks, array $generated = []): void
   {
      $BOLD  = self::wrap(self::_BOLD_STYLE);
      $DIM   = self::wrap(self::_DIM_STYLE);
      $RESET = self::_RESET_FORMAT;

      echo "\n{$BOLD}  Artifacts{$RESET}\n";

      // # Marks — one file per round
      foreach ($marks as $file) {
         echo "{$DIM}  Marks   {$file}{$RESET}\n";
      }

      // # Report + charts
      foreach ($generated as $file) {
         $label = str_ends_with($file, '.svg') ? 'Chart' : 'Report';
         echo "{$DIM}  " . str_pad($label, 8) . "{$file}{$RESET}\n";
      }

      echo "\n";
   }

   /**
    * Serialize a full run (all rounds) to a JSON document.
    *
    * Emitted as the LAST stdout document in `--format=json` mode, so
    * machine consumers can `tail -n 1` the output.
    *
    * @param string $caseName
    * @param string $metric
    * @param array<string,scalar|array<int,scalar>> $config Non-swept run configuration.
    * @param array<string,array<int,int>> $sweeps Swept option name => expanded values.
    * @param array<int,array{options:array<string,scalar>,results:array<string,array<string,Result>>,marks:string}> $rounds
    * @param array<int,string> $artifacts Report/chart paths (when generated).
    */
   public static function export (
      string $caseName,
      string $metric,
      array $config,
      array $sweeps,
      array $rounds,
      array $artifacts = []
   ): string
   {
      $document = [
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
      return json_encode($document) . "\n";
   }
}
