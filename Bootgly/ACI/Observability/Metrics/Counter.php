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
use InvalidArgumentException;

use Bootgly\ACI\Observability\Data\Types;
use Bootgly\ACI\Observability\Metric;


class Counter extends Metric
{
   public Types $Type { get => Types::Counter; }

   // * Data
   public private(set) float $value = 0.0;
   // @ Optional observable callback — when set, read() pulls a live total instead of the stored one
   //   (used to bridge externally-maintained monotonic counters, e.g. server socket stats).
   protected null|Closure $observe;


   /**
    * Build a counter instrument (incrementable, or observable via a callback).
    *
    * @param string $name Metric name (e.g. `http_requests_total`).
    * @param string $help Human-readable description.
    * @param array<string, string> $labels Static label set identifying this series.
    * @param null|Closure $observe Optional callback returning the current cumulative total.
    */
   public function __construct (string $name, string $help = '', array $labels = [], null|Closure $observe = null)
   {
      parent::__construct($name, $help, $labels);

      // * Data
      $this->observe = $observe;
   }

   /**
    * Increment the counter — counters are monotonic and never decrease.
    *
    * @param int|float $by Non-negative amount to add (default 1).
    * @return void
    * @throws InvalidArgumentException When $by is negative.
    */
   public function increment (int|float $by = 1): void
   {
      // ? Counters cannot decrease
      if ($by < 0) {
         throw new InvalidArgumentException('Counter->increment() requires a non-negative amount.');
      }

      $this->value += $by;
   }

   /**
    * Read the counter's current value (from the observable callback when present).
    *
    * @return array<string, mixed> `{labels, value}`.
    */
   public function read (): array
   {
      // @ Pull a live total when observable, else the stored value
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
