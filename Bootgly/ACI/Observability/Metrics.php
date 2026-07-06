<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Observability;


class Metrics
{
   // * Data
   /** @var array<int, Metric> */
   public protected(set) array $Metrics = [];


   /**
    * Register a metric instrument.
    *
    * @param Metric $Metric The instrument to add.
    * @return self
    */
   public function push (Metric $Metric): self
   {
      $this->Metrics[] = $Metric;

      return $this;
   }

   /**
    * Read every registered instrument, grouped by metric name into series.
    *
    * @return array<string, array{type: string, help: string, series: list<array<string, mixed>>}>
    */
   public function read (): array
   {
      $metrics = [];

      foreach ($this->Metrics as $Metric) {
         $name = $Metric->name;

         $metrics[$name] ??= [
            'type'   => $Metric->Type->value,
            'help'   => $Metric->help,
            'series' => [],
         ];

         $metrics[$name]['series'][] = $Metric->read();
      }

      // :
      return $metrics;
   }
}
