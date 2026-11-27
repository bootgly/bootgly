<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Observability;


use function is_array;
use function ksort;
use function serialize;


class Collectors
{
   // * Data
   /** @var array<int, Collector> */
   public protected(set) array $Collectors = [];


   /**
    * Register a metric collector source.
    *
    * @param Collector $Collector The collector to add.
    * @return self
    */
   public function push (Collector $Collector): self
   {
      $this->Collectors[] = $Collector;

      return $this;
   }

   /**
    * Collect every registered source, merging their metrics by name (see
    * `fold()`): the first source to report a series wins.
    *
    * @return array<string, array{type: string, help: string, series: list<array<string, mixed>>}>
    */
   public function collect (): array
   {
      $metrics = [];

      // @@
      foreach ($this->Collectors as $Collector) {
         $metrics = self::fold($metrics, $Collector->collect());
      }

      // :
      return $metrics;
   }

   /**
    * Fold metrics read in this process into a metric set, by name.
    *
    * A name not in the set is added. A same-named metric of another type is
    * dropped, as `Snapshot::merge()` does. A same-named metric of the same
    * type adds the series whose label set the metric does not hold yet; a
    * series it already holds keeps its first reading — two readings of one
    * series in one process are the same series, never summed.
    *
    * @param array<string, array{type: string, help: string, series: list<array<string, mixed>>}> $metrics The set folded into.
    * @param array<string, array{type: string, help: string, series: list<array<string, mixed>>}> $incoming The metrics folded in.
    *
    * @return array<string, array{type: string, help: string, series: list<array<string, mixed>>}>
    */
   public static function fold (array $metrics, array $incoming): array
   {
      // @@
      foreach ($incoming as $name => $metric) {
         // ? A name the set does not hold
         if (isSet($metrics[$name]) === false) {
            $metrics[$name] = $metric;
            continue;
         }
         // ? Same name, another type — the set's metric is kept
         if ($metrics[$name]['type'] !== $metric['type']) {
            continue;
         }

         // ! The label sets the metric already holds
         $held = [];
         foreach ($metrics[$name]['series'] as $series) {
            $labels = is_array($series['labels'] ?? null) ? $series['labels'] : [];
            ksort($labels);
            $held[serialize($labels)] = true;
         }

         // @@ Add the series of a label set not held yet
         foreach ($metric['series'] as $series) {
            $labels = is_array($series['labels'] ?? null) ? $series['labels'] : [];
            ksort($labels);
            $key = serialize($labels);
            if (isSet($held[$key])) {
               continue;
            }

            $held[$key] = true;
            $metrics[$name]['series'][] = $series;
         }
      }

      // :
      return $metrics;
   }
}
