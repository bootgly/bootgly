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


use Bootgly\ACI\Observability\Data\Types;


abstract class Metric
{
   // * Config
   public private(set) string $name;
   public private(set) string $help;
   /** @var array<string, string> */
   public private(set) array $labels;

   // @ Fixed instrument kind, provided by each concrete metric.
   abstract public Types $Type { get; }


   /**
    * Build a metric instrument identifying a single series.
    *
    * @param string $name Metric name (e.g. `http_requests_total`).
    * @param string $help Human-readable description.
    * @param array<string, string> $labels Static label set identifying this series.
    */
   public function __construct (string $name, string $help = '', array $labels = [])
   {
      // * Config
      $this->name = $name;
      $this->help = $help;
      $this->labels = $labels;
   }

   /**
    * Read this instrument's current sample(s) as a normalized array.
    *
    * @return array<string, mixed> Counter/Gauge: `{labels, value}`; Histogram: `{labels, buckets, sum, count}`.
    */
   abstract public function read (): array;
}
