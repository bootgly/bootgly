<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Observability;


use Bootgly\ACI\Observability\Data\Types;


abstract class Collector
{
   /**
    * Collect this source's current metrics, grouped by metric name.
    *
    * @return array<string, array{type: string, help: string, series: list<array<string, mixed>>}>
    */
   abstract public function collect (): array;

   /**
    * Compose a single-series metric entry of a given instrument kind.
    *
    * @param Types $Type Instrument kind (Counter/Gauge/Histogram).
    * @param string $help Human-readable description.
    * @param array<string, string> $labels Series labels.
    * @param float $value Current value.
    * @return array{type: string, help: string, series: list<array<string, mixed>>}
    */
   protected function compose (Types $Type, string $help, array $labels, float $value): array
   {
      // :
      return [
         'type'   => $Type->value,
         'help'   => $help,
         'series' => [['labels' => $labels, 'value' => $value]],
      ];
   }
}
