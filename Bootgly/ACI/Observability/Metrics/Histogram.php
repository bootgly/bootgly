<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Observability\Metrics;


use function array_fill;
use function array_unique;
use function count;
use function is_finite;
use function sort;
use InvalidArgumentException;

use Bootgly\ACI\Observability\Data\Types;
use Bootgly\ACI\Observability\Metric;


class Histogram extends Metric
{
   // Default upper bounds in seconds (Prometheus client defaults).
   /** @var list<float> */
   public const array BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0];

   public Types $Type { get => Types::Histogram; }

   // * Config
   /** @var list<float> Upper bounds (le), ascending. */
   public private(set) array $buckets;

   // * Data
   /** @var array<int, int> Per-bucket cumulative counts, aligned to $buckets. */
   public private(set) array $counts;
   public private(set) float $sum = 0.0;
   public private(set) int $count = 0;


   /**
    * Build a histogram instrument with the given (or default) buckets.
    *
    * @param string $name Metric name.
    * @param string $help Human-readable description.
    * @param array<string, string> $labels Static label set identifying this series.
    * @param list<float> $buckets Upper bounds (le); sorted ascending on construction.
    * @throws InvalidArgumentException When buckets are empty, non-finite (NAN/INF), or not unique.
    */
   public function __construct (string $name, string $help = '', array $labels = [], array $buckets = self::BUCKETS)
   {
      parent::__construct($name, $help, $labels);

      // ? Validate bucket bounds — non-empty, finite, unique (else the distribution is invalid/lossy)
      if ($buckets === []) {
         throw new InvalidArgumentException('Histogram requires at least one bucket bound.');
      }
      foreach ($buckets as $bound) {
         if (is_finite($bound) === false) {
            throw new InvalidArgumentException('Histogram bucket bounds must be finite (no NAN/INF).');
         }
      }
      sort($buckets);
      if (count(array_unique($buckets)) !== count($buckets)) {
         throw new InvalidArgumentException('Histogram bucket bounds must be unique.');
      }

      // * Config
      $this->buckets = $buckets;

      // * Data
      $this->counts = array_fill(0, count($buckets), 0);
   }

   /**
    * Record one observation across the configured buckets.
    *
    * @param float $value Observed value.
    * @return void
    */
   public function observe (float $value): void
   {
      // @ A value falls into every bucket whose upper bound it does not exceed
      foreach ($this->buckets as $i => $le) {
         if ($value <= $le) {
            $this->counts[$i]++;
         }
      }

      $this->sum += $value;
      $this->count++;
   }

   /**
    * Read the histogram as cumulative `le => count` buckets plus sum and total count.
    *
    * @return array<string, mixed> `{labels, buckets, sum, count}`.
    */
   public function read (): array
   {
      // @ Map each upper bound to its (already cumulative) count
      $buckets = [];
      foreach ($this->buckets as $i => $le) {
         $buckets[(string) $le] = $this->counts[$i];
      }
      // @ +Inf bucket equals the total number of observations
      $buckets['+Inf'] = $this->count;

      // :
      return [
         'labels'  => $this->labels,
         'buckets' => $buckets,
         'sum'     => $this->sum,
         'count'   => $this->count,
      ];
   }
}
