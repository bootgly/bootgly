<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Observability\Metrics;


use Closure;

use Bootgly\ACI\Observability\Data\Types;
use Bootgly\ACI\Observability\Metric;


class Gauge extends Metric
{
   public Types $Type { get => Types::Gauge; }

   // * Data
   public private(set) float $value = 0.0;
   // @ Optional observable callback — when set, read() pulls a live value instead of the stored one.
   protected null|Closure $observe;


   /**
    * Build a gauge instrument (settable, or observable via a callback).
    *
    * @param string $name Metric name.
    * @param string $help Human-readable description.
    * @param array<string, string> $labels Static label set identifying this series.
    * @param null|Closure $observe Optional callback returning the current value (observable gauge).
    */
   public function __construct (string $name, string $help = '', array $labels = [], null|Closure $observe = null)
   {
      parent::__construct($name, $help, $labels);

      // * Data
      $this->observe = $observe;
   }

   /**
    * Set the gauge to an absolute value.
    *
    * @param float $value The new value.
    * @return void
    */
   public function set (float $value): void
   {
      $this->value = $value;
   }

   /**
    * Increase the gauge by an amount.
    *
    * @param int|float $by Amount to add (default 1).
    * @return void
    */
   public function increment (int|float $by = 1): void
   {
      $this->value += $by;
   }

   /**
    * Decrease the gauge by an amount.
    *
    * @param int|float $by Amount to subtract (default 1).
    * @return void
    */
   public function decrement (int|float $by = 1): void
   {
      $this->value -= $by;
   }

   /**
    * Read the gauge's current value (from the observable callback when present).
    *
    * @return array<string, mixed> `{labels, value}`.
    */
   public function read (): array
   {
      // @ Pull a live value when observable, else the stored value
      $value = $this->observe !== null
         ? (float) ($this->observe)()
         : $this->value;

      // :
      return [
         'labels' => $this->labels,
         'value'  => $value,
      ];
   }
}
