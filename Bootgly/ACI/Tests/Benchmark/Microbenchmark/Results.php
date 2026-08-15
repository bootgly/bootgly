<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Benchmark\Microbenchmark;


use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const PHP_EOL;
use function array_column;
use function array_map;
use function array_key_last;
use function array_search;
use function count;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function implode;
use function is_array;
use function gettype;
use function is_dir;
use function is_scalar;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;
use function max;
use function min;
use function mkdir;
use function number_format;
use function reset;
use function round;
use function sort;
use function sprintf;


/**
 * Stored microbenchmark results — the evidence trail.
 *
 * Results are committed alongside the cases on purpose. A microbenchmark whose
 * numbers live only in a terminal has to be re-derived by every reader; stored
 * per PHP version, the folder becomes a history that answers "which is faster
 * here?" before anyone spends a machine on it.
 *
 * That history is a starting point, never an answer: a ratio measured on one
 * machine, build and workload can differ on another. Re-run before relying.
 *
 * @phpstan-type Row array{
 *    label:string, ns:float, ratio:float, spread:float, floor_bound?:bool,
 *    cross_process_spread?:float
 * }
 * @phpstan-type Section array{
 *    section:string, baseline:string, fastest:string, recommendation:string,
 *    stable:bool, worst_spread:float, verdict:string, rows:array<int,Row>,
 *    cross_process_spread?:float
 * }
 * @phpstan-type Payload array{
 *    case:string, title:string, measured:string, runtime:array<string,string>,
 *    inputs:array<string,mixed>,
 *    method:array{
 *       iterations:int, rounds:int, warmup:int, estimator:string, floor_ns:float,
 *       processes?:int
 *    },
 *    sections:array<int,Section>
 * }
 */
class Results
{
   /**
    * Persist one run as `<case>.php-<version>.json`.
    *
    * One file per case per PHP version: re-running a version refreshes it, a
    * new version adds a file.
    *
    * @param Payload $Payload
    */
   public static function save (string $directory, array $Payload): string
   {
      if ( is_dir($directory) === false ) {
         mkdir($directory, 0o775, true);
      }

      $file = "{$directory}/{$Payload['case']}.php-{$Payload['runtime']['php']}.json";

      file_put_contents($file, json_encode($Payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

      return $file;
   }

   /**
    * Read every stored run, grouped by case then PHP version.
    *
    * @return array<string,array<string,Payload>>
    */
   public static function load (string $directory): array
   {
      $files = glob("{$directory}/*.json") ?: [];
      sort($files);

      $Runs = [];
      foreach ($files as $file) {
         /** @var null|Payload $Payload */
         $Payload = json_decode((string) file_get_contents($file), true);

         if ( is_array($Payload) === false ) {
            continue;
         }

         $Runs[$Payload['case']][$Payload['runtime']['php']] = $Payload;
      }

      ksort($Runs);

      return $Runs;
   }

   /**
    * Fold several single-process payloads into one, keeping the best round per
    * row and recording how far the processes disagreed.
    *
    * Best-of-N rounds inside ONE process cannot see the variance that lives
    * between processes: PHP's tracing JIT does not always make the same
    * compilation decisions, so a case can be consistently fast in one process
    * and consistently slow in the next — stable within each, bimodal across
    * them. `cross_process_spread` is what exposes that to a reader.
    *
    * @param non-empty-array<int,Payload> $Payloads
    * @return Payload
    */
   public static function merge (array $Payloads): array
   {
      $Merged = $Payloads[array_key_last($Payloads)];

      // @@
      foreach ($Merged['sections'] as $index => &$Section) {
         $samples = [];
         foreach ($Payloads as $Payload) {
            foreach ($Payload['sections'][$index]['rows'] ?? [] as $Row) {
               $samples[$Row['label']][] = $Row['ns'];
            }
         }

         $best = [];
         foreach ($Section['rows'] as &$Row) {
            $collected = $samples[$Row['label']] ?? [];

            if ($collected === []) {
               $collected = [$Row['ns']];
            }

            $fastest = min($collected);

            $Row['ns'] = round($fastest, 1);
            $Row['cross_process_spread'] = round(max($collected) / max($fastest, 0.1), 3);

            $best[$Row['label']] = $Row['ns'];
         }
         unset($Row);

         // ? A section with no rows has nothing to rank
         if ($best === []) {
            continue;
         }

         // ! Ratios recomputed from the merged numbers, against the declared baseline
         $baseline = $best[$Section['baseline']] ?? reset($best);
         foreach ($Section['rows'] as &$Row) {
            $Row['ratio'] = round($Row['ns'] / max($baseline, 0.1), 4);
         }
         unset($Row);

         $fastest = array_search(min($best), $best, true);
         $Section['fastest'] = is_string($fastest) ? $fastest : $Section['fastest'];

         // ! Rows at the harness resolution limit cannot report a meaningful
         //   spread — judge the section's stability on the rows that can
         $spreads = [];
         foreach ($Section['rows'] as $Row) {
            if ( ($Row['floor_bound'] ?? false) === false ) {
               $spreads[] = $Row['cross_process_spread'];
            }
         }

         $spread = $spreads === [] ? 1.0 : max($spreads);
         $Section['cross_process_spread'] = $spread;
         $Section['stable'] = $spread <= 1.25;
      }
      unset($Section);

      $Merged['method']['processes'] = count($Payloads);
      $Merged['method']['estimator'] = 'best round across ' . count($Payloads)
         . ' processes, closure floor subtracted';

      // :
      return $Merged;
   }

   /**
    * Render every stored run as markdown — the decision table first.
    */
   public static function render (string $directory): string
   {
      $Runs = self::load($directory);

      $lines = [];
      $lines[] = '# Microbenchmark results';
      $lines[] = '';
      $lines[] = 'Generated from `results/*.json`. One file per case per PHP version, so this';
      $lines[] = 'page doubles as the version history.';
      $lines[] = '';
      $lines[] = '> **A starting point, not an answer.** These ratios come from one machine under';
      $lines[] = '> one workload. Re-run the case on your target runtime before relying on it.';
      $lines[] = '';
      $lines[] = '🏆 marks the fastest measurement of each comparison — scan for it to see';
      $lines[] = 'which mechanism wins a scenario without reading the numbers.';
      $lines[] = '';
      $lines[] = '## What to use';
      $lines[] = '';
      $lines[] = '| Case | PHP | Inputs | Comparison | **Use this** | Fastest measured | Gain | Stable |';
      $lines[] = '|---|---|---|---|---|---|---|---|';

      foreach ($Runs as $case => $Versions) {
         foreach ($Versions as $version => $Payload) {
            $inputs = $Payload['inputs'] !== []
               ? '`' . self::describe($Payload['inputs']) . '`'
               : '—';

            foreach ($Payload['sections'] as $Section) {
               $ratio = 1.0;
               foreach ($Section['rows'] as $Row) {
                  if ($Row['label'] === $Section['fastest']) {
                     $ratio = $Row['ratio'];

                     break;
                  }
               }

               $lines[] = sprintf(
                  '| `%s` | %s | %s | %s | **%s** | %s | %s | %s |',
                  $case,
                  $version,
                  $inputs,
                  $Section['section'] !== '' ? $Section['section'] : '—',
                  $Section['recommendation'],
                  '🏆 ' . $Section['fastest'],
                  $ratio < 1 ? sprintf('%.0f%% faster', (1 - $ratio) * 100) : 'baseline is fastest',
                  $Section['stable']
                     ? 'yes'
                     : sprintf('**NO** (%.2fx)', $Section['cross_process_spread'] ?? $Section['worst_spread'])
               );
            }
         }
      }

      // @@ Full tables
      $lines[] = '';
      $lines[] = '## Full measurements';

      foreach ($Runs as $case => $Versions) {
         $lines[] = '';
         $lines[] = "### `{$case}`";

         foreach ($Versions as $version => $Payload) {
            $Runtime = $Payload['runtime'];

            $lines[] = '';
            $lines[] = sprintf(
               '**PHP %s** — opcache %s, JIT %s, %s · %s · best-of-%d x %s iterations, floor %.1f ns',
               $version,
               $Runtime['opcache'],
               $Runtime['jit'],
               $Runtime['os'],
               $Payload['measured'],
               $Payload['method']['rounds'],
               number_format($Payload['method']['iterations']),
               $Payload['method']['floor_ns']
            );

            if ($Payload['inputs'] !== []) {
               $lines[] = '';
               $lines[] = '`inputs: ' . self::describe($Payload['inputs']) . '`';
            }

            foreach ($Payload['sections'] as $Section) {
               $lines[] = '';

               if ($Section['section'] !== '') {
                  $lines[] = "*{$Section['section']}*";
                  $lines[] = '';
               }

               $lines[] = '| Measurement | ns/op | vs baseline |';
               $lines[] = '|---|---:|---:|';

               foreach ($Section['rows'] as $Row) {
                  $lines[] = sprintf(
                     '| %s | %.1f | %.2fx |',
                     $Row['label'] === $Section['fastest']
                        ? "🏆 **{$Row['label']}**"
                        : $Row['label'],
                     $Row['ns'],
                     $Row['ratio']
                  );
               }

               $lines[] = '';
               $lines[] = '**Use:** ' . $Section['recommendation'];

               if ($Section['verdict'] !== '') {
                  $lines[] = '';
                  $lines[] = "> {$Section['verdict']}";
               }
            }
         }
      }

      $lines[] = '';

      // :
      return implode(PHP_EOL, $lines);
   }

   /**
    * Render resolved inputs — comma separated, since a pipe would break the
    * markdown tables this feeds.
    *
    * @param array<string,mixed> $inputs
    */
   public static function describe (array $inputs): string
   {
      $parts = [];
      foreach ($inputs as $name => $value) {
         $rendered = match (true) {
            is_array($value) => implode(',', array_map(
               static fn (mixed $piece): string => is_scalar($piece) ? (string) $piece : gettype($piece),
               $value
            )),
            is_scalar($value) => (string) $value,
            default => gettype($value),
         };

         $parts[] = "{$name}={$rendered}";
      }

      return implode(', ', $parts);
   }
}
