<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Observability\Data;


use function array_keys;
use function is_array;
use function is_float;
use function is_int;
use function json_encode;
use function ksort;
use function microtime;


class Snapshot
{
   // * Data
   public float $timestamp;
   /**
    * Gathered metric series grouped by metric name.
    *
    * @var array<string, array{type: string, help: string, series: list<array<string, mixed>>}>
    */
   public array $metrics;


   /**
    * Build a point-in-time snapshot of gathered metrics.
    *
    * @param array<string, array{type: string, help: string, series: list<array<string, mixed>>}> $metrics
    *        Metric series grouped by name.
    */
   public function __construct (array $metrics = [])
   {
      // * Data
      $this->metrics = $metrics;
      $this->timestamp = microtime(true);
   }

   /**
    * Merge another snapshot into this one, combining matching series additively.
    *
    * Series are matched by metric name + label set: a collision sums counter/gauge values and
    * adds histogram buckets/sum/count; non-matching series are unioned. Used by the master to
    * fold per-worker snapshots into one cluster view.
    *
    * @param Snapshot $Snapshot The snapshot to merge in.
    * @return self
    */
   public function merge (Snapshot $Snapshot): self
   {
      foreach ($Snapshot->metrics as $name => $metric) {
         // ? New metric name — copy wholesale
         if (isSet($this->metrics[$name]) === false) {
            $this->metrics[$name] = $metric;
            continue;
         }

         // ? Same name, different type — keep the existing metric, drop the incompatible one
         if ($this->metrics[$name]['type'] !== $metric['type']) {
            continue;
         }

         // ! Work on a typed local series list, then write it back
         $series = $this->metrics[$name]['series'];

         // @ Merge each incoming series by label identity
         foreach ($metric['series'] as $incoming) {
            $labels = is_array($incoming['labels'] ?? null) ? $incoming['labels'] : [];
            $key = self::identify($labels);

            // # Locate a matching existing series (same labels)
            $found = null;
            foreach ($series as $i => $had) {
               $hadLabels = is_array($had['labels'] ?? null) ? $had['labels'] : [];
               if (self::identify($hadLabels) === $key) {
                  $found = $i;
                  break;
               }
            }

            // ?: No match — union; else combine additively
            if ($found === null) {
               $series[] = $incoming;
            }
            else {
               $series[$found] = self::combine($series[$found], $incoming);
            }
         }

         $this->metrics[$name]['series'] = $series;
      }

      return $this;
   }

   /**
    * Rebuild a snapshot from a decoded JSON document (as produced by the JSON exporter).
    *
    * Used to reconstruct snapshots read back from per-worker JSON files (or any decoded document).
    *
    * @param array<array-key, mixed> $data Decoded fields: timestamp, metrics.
    * @return self
    */
   public static function import (array $data): self
   {
      // @ Metrics (trust the decoded shape; producers are the JSON exporter)
      /** @var array<string, array{type: string, help: string, series: list<array<string, mixed>>}> $metrics */
      $metrics = is_array($data['metrics'] ?? null) ? $data['metrics'] : [];
      $Snapshot = new self($metrics);

      // @ Original timestamp
      $timestamp = $data['timestamp'] ?? null;
      if ( is_int($timestamp) || is_float($timestamp) ) {
         $Snapshot->timestamp = (float) $timestamp;
      }

      // :
      return $Snapshot;
   }

   /**
    * Build a stable, order-independent identity key for a label set.
    *
    * @param array<array-key, mixed> $labels
    * @return string
    */
   private static function identify (array $labels): string
   {
      ksort($labels);

      $json = json_encode($labels);

      // :
      return $json === false ? '' : $json;
   }

   /**
    * Combine two matching series additively (counter/gauge value, histogram buckets/sum/count).
    *
    * @param array<string, mixed> $a Existing series (mutated copy returned).
    * @param array<string, mixed> $b Incoming series.
    * @return array<string, mixed>
    */
   private static function combine (array $a, array $b): array
   {
      $aBuckets = $a['buckets'] ?? null;
      $bBuckets = $b['buckets'] ?? null;

      // # Histogram series — combine only when bucket schemas match; blending different `le` sets
      //   would produce an invalid cumulative distribution, so keep the existing series intact
      if ( is_array($aBuckets) && is_array($bBuckets) ) {
         if (array_keys($aBuckets) !== array_keys($bBuckets)) {
            return $a;
         }

         foreach ($bBuckets as $le => $count) {
            $aBuckets[$le] = (int) self::normalize($aBuckets[$le] ?? 0) + (int) self::normalize($count);
         }
         $a['buckets'] = $aBuckets;

         if ( isSet($a['sum'], $b['sum']) ) {
            $a['sum'] = self::normalize($a['sum']) + self::normalize($b['sum']);
         }
         if ( isSet($a['count'], $b['count']) ) {
            $a['count'] = (int) self::normalize($a['count']) + (int) self::normalize($b['count']);
         }

         // :
         return $a;
      }

      // # Counter / Gauge value
      if ( isSet($a['value'], $b['value']) ) {
         $a['value'] = self::normalize($a['value']) + self::normalize($b['value']);
      }

      // :
      return $a;
   }

   /**
    * Coerce a decoded value to a float (0.0 when not numeric); callers cast to int where needed.
    *
    * @param mixed $value
    * @return float
    */
   private static function normalize (mixed $value): float
   {
      // :
      return is_int($value) || is_float($value) ? (float) $value : 0.0;
   }
}
