<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Observability\Exporters;


use function implode;
use function is_array;
use function is_float;
use function is_infinite;
use function is_int;
use function is_nan;
use function is_scalar;
use function is_string;
use function ksort;
use function str_replace;

use Bootgly\ACI\Observability\Data\Snapshot;
use Bootgly\ACI\Observability\Exporter;


class Prometheus implements Exporter
{
   // * Config
   public string $namespace;


   /**
    * Build a Prometheus text-exposition exporter.
    *
    * @param string $namespace Optional metric-name prefix (e.g. `bootgly` → `bootgly_http_requests_total`).
    */
   public function __construct (string $namespace = '')
   {
      // * Config
      $this->namespace = $namespace;
   }

   /**
    * Encode a snapshot as Prometheus text exposition format (version 0.0.4).
    *
    * Each metric emits a `# HELP` and `# TYPE` line followed by one sample line per series;
    * histograms expand to `_bucket`/`_sum`/`_count` lines (buckets are already cumulative).
    *
    * @param Snapshot $Snapshot The snapshot to encode.
    * @return string The exposition text (empty when there are no metrics).
    */
   public function export (Snapshot $Snapshot): string
   {
      $lines = [];

      foreach ($Snapshot->metrics as $name => $metric) {
         // # Name (optionally prefixed) + metadata
         $name = $this->namespace === '' ? $name : "{$this->namespace}_{$name}";
         $type = $metric['type'];
         $help = $metric['help'];

         if ($help !== '') {
            $lines[] = "# HELP $name " . $this->escape($help, false);
         }
         $lines[] = "# TYPE $name $type";

         // @ One block per series
         foreach ($metric['series'] as $Series) {
            $labels = is_array($Series['labels'] ?? null) ? $Series['labels'] : [];

            // ?: Histogram expands to bucket/sum/count; others are a single sample
            if ($type === 'histogram') {
               $buckets = is_array($Series['buckets'] ?? null) ? $Series['buckets'] : [];
               foreach ($buckets as $le => $count) {
                  $lines[] = "{$name}_bucket" . $this->compose($labels, ['le' => (string) $le]) . ' ' . $this->format($count);
               }
               $lines[] = "{$name}_sum" . $this->compose($labels) . ' ' . $this->format($Series['sum'] ?? 0);
               $lines[] = "{$name}_count" . $this->compose($labels) . ' ' . $this->format($Series['count'] ?? 0);
            }
            else {
               $lines[] = $name . $this->compose($labels) . ' ' . $this->format($Series['value'] ?? 0);
            }
         }
      }

      // :
      return $lines === [] ? '' : implode("\n", $lines) . "\n";
   }

   /**
    * Compose a `{k="v",...}` label block (sorted for stable output), merging extra labels.
    *
    * @param array<array-key, mixed> $labels Series labels (values coerced to string).
    * @param array<string, string> $extra Extra labels to add (e.g. `le` for buckets).
    * @return string The label block, or '' when there are no labels.
    */
   private function compose (array $labels, array $extra = []): string
   {
      $pairs = [];

      foreach ($labels as $key => $value) {
         if (is_string($key) === false) {
            continue;
         }
         $pairs[$key] = is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
      }
      foreach ($extra as $key => $value) {
         $pairs[$key] = $value;
      }

      // ? No labels — no block
      if ($pairs === []) {
         return '';
      }

      ksort($pairs);

      $out = [];
      foreach ($pairs as $key => $value) {
         $out[] = $key . '="' . $this->escape($value, true) . '"';
      }

      // :
      return '{' . implode(',', $out) . '}';
   }

   /**
    * Format a metric value for exposition (Inf/NaN tokens; integers stay compact).
    *
    * @param mixed $value
    * @return string
    */
   private function format (mixed $value): string
   {
      if (is_int($value)) {
         return (string) $value;
      }
      if (is_float($value)) {
         if (is_nan($value)) {
            return 'NaN';
         }
         if (is_infinite($value)) {
            return $value > 0 ? '+Inf' : '-Inf';
         }

         return (string) $value;
      }

      // :
      return '0';
   }

   /**
    * Escape text for HELP (`\`, newline) and, when quoted, label values (also `"`).
    *
    * @param string $text
    * @param bool $quoted True for label values (escape double quotes too).
    * @return string
    */
   private function escape (string $text, bool $quoted): string
   {
      $text = str_replace(['\\', "\n"], ['\\\\', '\\n'], $text);

      if ($quoted === true) {
         $text = str_replace('"', '\\"', $text);
      }

      // :
      return $text;
   }
}
