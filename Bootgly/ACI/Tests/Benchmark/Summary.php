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


use function array_keys;
use function array_map;
use function count;
use function date;
use function file_put_contents;
use function implode;
use function in_array;
use function is_dir;
use function max;
use function mkdir;
use function number_format;
use function sprintf;
use function str_pad;
use function str_repeat;
use function strlen;
use function strtolower;
use function uasort;
use const PHP_INT_MAX;
use const STR_PAD_RIGHT;

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
    * Print competitors and scenarios summary.
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

      // # Competitors
      echo "{$BOLD}  Competitors{$RESET}\n";

      $isFirst = true;
      foreach ($Runner->competitors as $Competitor) {
         if ($Configs->competitors !== null && !in_array(strtolower($Competitor->name), array_map(strtolower(...), $Configs->competitors))) {
            continue;
         }

         $version = $Competitor->version !== '' ? " {$Competitor->version}" : '';
         $baseline = $isFirst ? "{$DIM}  [baseline]{$RESET}" : '';

         echo "{$CYAN}  ▸ {$RESET}"
            . "{$BOLD}{$Competitor->name}{$RESET}"
            . $version . $baseline . "\n";

         $isFirst = false;
      }

      // # Scenarios
      if ($Runner->scenarios !== []) {
         $scenarios = $Runner->scenarios;

         // ? Apply scenario filter
         $filtered = [];
         foreach ($scenarios as $index => $Scenario) {
         if ($Configs->scenarios !== null && !in_array($index + 1, $Configs->scenarios)) {
               continue;
            }
            $filtered[$index] = $Scenario;
         }

         echo "\n{$BOLD}  Scenarios (" . count($filtered) . "){$RESET}\n";

         $prevGroup = '';
         foreach ($filtered as $index => $Scenario) {
            if ($Scenario->group !== '' && $Scenario->group !== $prevGroup) {
               echo "    {$BOLD}{$Scenario->group}{$RESET}\n";
               $prevGroup = $Scenario->group;
            }

            echo "{$DIM}      " . ($index + 1) . ". {$Scenario->label}{$RESET}\n";
         }
      }

      self::separate();
   }

   /**
    * Print results table (auto-detects code vs server).
    *
    * @param array<string,array<string,Result>> $results
    */
   public static function report (array $results): void
   {
      $allCompetitors = array_keys($results);

      if (count($allCompetitors) === 0) {
         return;
      }

      $BOLD    = self::wrap(self::_BOLD_STYLE);
      $DIM     = self::wrap(self::_DIM_STYLE);
      $GREEN   = self::wrap(self::_GREEN_FOREGROUND);
      $RED     = self::wrap(self::_RED_FOREGROUND);
      $YELLOW  = self::wrap(self::_YELLOW_FOREGROUND);
      $CYAN    = self::wrap(self::_CYAN_FOREGROUND);
      $RESET   = self::_RESET_FORMAT;

      self::separate();

      // @ Results header
      echo "{$BOLD}  Results\n{$RESET}\n";
      echo "  {$CYAN}" . implode(' vs ', $allCompetitors) . "{$RESET}\n\n";

      // @ Detect type
      $isServer = false;
      foreach ($results as $scenarios) {
         foreach ($scenarios as $Result) {
            if ($Result->rps !== null) {
               $isServer = true;
            }
            break 2;
         }
      }

      if ($isServer) {
         $competitors = array_keys($results);

         if (count($competitors) === 0) {
            return;
         }

         // @ Collect all scenarios
         $allScenarios = [];
         foreach ($results as $scenarios) {
            foreach (array_keys($scenarios) as $label) {
               if (!in_array($label, $allScenarios)) {
                  $allScenarios[] = $label;
               }
            }
         }

         // # Column widths
         $scenarioWidth = 20;
         foreach ($allScenarios as $label) {
            $scenarioWidth = max($scenarioWidth, strlen($label) + 2);
         }

         // ? Solo mode (single competitor)
         if (count($competitors) === 1) {
            $name = $competitors[0];

            echo "{$BOLD}  {$name}{$RESET}\n";

            $header = str_pad('Scenario', $scenarioWidth, ' ', STR_PAD_RIGHT)
               . str_pad('Req/s', 16, ' ', STR_PAD_RIGHT)
               . str_pad('Latency', 16, ' ', STR_PAD_RIGHT)
               . 'Transfer/s';

            echo "{$BOLD}  {$header}{$RESET}\n";
            echo "  " . str_repeat('─', strlen($header) + 4) . "\n";

            foreach ($allScenarios as $label) {
               $Result = $results[$name][$label] ?? new Result;

               $rps = $Result->rps !== null ? number_format((int) $Result->rps) : 'N/A';
               $latency = $Result->latency ?? 'N/A';
               $transfer = $Result->transfer ?? 'N/A';

               echo "  "
                  . str_pad($label, $scenarioWidth, ' ', STR_PAD_RIGHT)
                  . str_pad($rps, 16, ' ', STR_PAD_RIGHT)
                  . str_pad($latency, 16, ' ', STR_PAD_RIGHT)
                  . $transfer . "\n";
            }

            echo "\n";
            return;
         }

         // # Comparative mode (first competitor = baseline)
         $baseline = $competitors[0];

         foreach ($allScenarios as $label) {
            echo self::wrap(self::_MAGENTA_BOLD) . "  ── {$label} ──{$RESET}\n";

            $header = str_pad('Competitor', 20, ' ', STR_PAD_RIGHT)
               . str_pad('Req/s', 16, ' ', STR_PAD_RIGHT)
               . str_pad('Latency', 16, ' ', STR_PAD_RIGHT)
               . str_pad('Transfer/s', 16, ' ', STR_PAD_RIGHT)
               . 'vs ' . $baseline;

            echo "{$BOLD}  {$header}{$RESET}\n";
            echo "  " . str_repeat('─', strlen($header) + 4) . "\n";

            $baselineRps = $results[$baseline][$label]->rps ?? 0;

            foreach ($competitors as $name) {
               $Result = $results[$name][$label] ?? new Result;

               $rps = $Result->rps !== null ? number_format((int) $Result->rps) : 'N/A';
               $latency = $Result->latency ?? 'N/A';
               $transfer = $Result->transfer ?? 'N/A';

               // @ Diff% vs baseline
               $diff = '';
               if ($name === $baseline) {
                  $diff = "{$DIM}baseline{$RESET}";
               } elseif ($baselineRps > 0 && $Result->rps !== null) {
                  $pct = (($Result->rps - $baselineRps) / $baselineRps) * 100;
                  $sign = $pct >= 0 ? '+' : '';
                  // Positive = competitor faster than baseline = red (bad for baseline)
                  $diffColor = $pct >= 0 ? $RED : $GREEN;
                  $diff = $diffColor . sprintf('%s%.1f%%', $sign, $pct) . $RESET;
               }

               echo "  "
                  . str_pad($name, 20, ' ', STR_PAD_RIGHT)
                  . str_pad($rps, 16, ' ', STR_PAD_RIGHT)
                  . str_pad($latency, 16, ' ', STR_PAD_RIGHT)
                  . str_pad($transfer, 16, ' ', STR_PAD_RIGHT)
                  . $diff . "\n";
            }

            echo "\n";
         }
      }
      else {
         $competitors = array_keys($results);

         // @ Column widths
         $nameWidth = 12;
         foreach ($competitors as $name) {
            $nameWidth = max($nameWidth, strlen($name) + 2);
         }

         // @ Header
         $header = str_pad('Competitor', $nameWidth, ' ', STR_PAD_RIGHT)
            . str_pad('Time', 16, ' ', STR_PAD_RIGHT)
            . str_pad('Memory', 16, ' ', STR_PAD_RIGHT)
            . 'Position';

         echo "{$BOLD}  {$header}{$RESET}\n";
         echo "  " . str_repeat('─', strlen($header) + 4) . "\n";

         // @ Sort by time (ascending)
         $sorted = [];
         foreach ($results as $name => $scenarios) {
            $sorted[$name] = $scenarios['default'] ?? new Result;
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
               . str_pad($name, $nameWidth, ' ', STR_PAD_RIGHT)
               . str_pad($time, 16, ' ', STR_PAD_RIGHT)
               . str_pad($memory, 16, ' ', STR_PAD_RIGHT)
               . $medal
               . $RESET . "\n";

            $position++;
         }

         echo "\n";
      }
   }

   /**
    * Save results to .marks file.
    *
    * @param string $caseName
    * @param array<string,array<string,Result>> $results
    */
   public static function save (string $caseName, array $results): void
   {
      $dir = BOOTGLY_WORKING_DIR . 'workdata/tests/benchmarks/' . $caseName;

      if ( !is_dir($dir) ) {
         mkdir($dir, 0775, true);
      }

      $file = "$dir/" . date('Y-m-d_His') . '_bench.marks';

      $lines = [];
      $lines[] = "# Benchmark: {$caseName}";
      $lines[] = "# Date: " . date('Y-m-d H:i:s');
      $lines[] = "";

      foreach ($results as $competitor => $scenarios) {
         foreach ($scenarios as $label => $Result) {
            $line = "[{$competitor}][{$label}]";

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

      // @ Display save path (relative)
      $DIM   = self::wrap(self::_DIM_STYLE);
      $RESET = self::_RESET_FORMAT;
      $relative = "workdata/tests/benchmarks/$caseName/" . date('Y-m-d_His') . '_bench.marks';
      echo "\n{$DIM}  Results saved to: {$relative}{$RESET}\n\n";
   }
}
