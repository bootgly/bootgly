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
    * Collect every registered source, merging their metrics by name.
    *
    * @return array<string, array{type: string, help: string, series: list<array<string, mixed>>}>
    */
   public function collect (): array
   {
      $metrics = [];

      foreach ($this->Collectors as $Collector) {
         foreach ($Collector->collect() as $name => $metric) {
            $metrics[$name] = $metric;
         }
      }

      // :
      return $metrics;
   }
}
