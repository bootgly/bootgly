<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Benchmark\Latency;


use const PHP_INT_MAX;
use function array_fill;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function count;
use function intdiv;
use function is_array;
use function is_bool;
use function is_int;
use InvalidArgumentException;
use OverflowException;


/**
 * Fixed-memory, mergeable HDR-style latency distribution.
 *
 * Recording uses monotonic nanosecond deltas supplied by the caller. The dense
 * packed counter array is allocated once; sparse structures are created only
 * when the completed distribution is exported.
 */
final class Histogram
{
   public const string SCHEMA = 'bootgly.latency-hdr.v1';
   public const string UNIT = 'ns';
   public const int LOWEST_DISCERNIBLE_NS = 1_000;
   public const int HIGHEST_TRACKABLE_NS = 60_000_000_000;
   public const int MAXIMUM_HIGHEST_TRACKABLE_NS = 3_600_000_000_000;
   public const int SIGNIFICANT_DIGITS = 3;

   private const int UNIT_MAGNITUDE = 9;
   private const int SUB_BUCKET_MAGNITUDE = 11;
   private const int SUB_BUCKET_COUNT = 1 << self::SUB_BUCKET_MAGNITUDE;
   private const int HALF_BUCKET_MAGNITUDE = self::SUB_BUCKET_MAGNITUDE - 1;
   private const int HALF_BUCKET_COUNT = self::SUB_BUCKET_COUNT >> 1;
   private const int FIRST_UNTRACKABLE_NS = self::SUB_BUCKET_COUNT << self::UNIT_MAGNITUDE;

   private int $highestTrackableNS;
   private int $countsLength;

   /** @var list<int> */
   private array $counts;
   private int $count = 0;
   private null|int $sumNS = 0;
   private null|int $minimumNS = null;
   private null|int $maximumNS = null;
   private int $underflow = 0;
   private int $overflow = 0;


   public function __construct (int $highestTrackableNS = self::HIGHEST_TRACKABLE_NS)
   {
      if (
         $highestTrackableNS < self::LOWEST_DISCERNIBLE_NS
         || $highestTrackableNS > self::MAXIMUM_HIGHEST_TRACKABLE_NS
      ) {
         throw new InvalidArgumentException(
            'Latency histogram highest trackable value must be between 1 microsecond and 1 hour.'
         );
      }

      $this->highestTrackableNS = $highestTrackableNS;
      $this->countsLength = self::measure($highestTrackableNS);
      $this->counts = array_fill(0, $this->countsLength, 0);
   }

   /**
    * Record exactly one logical response latency.
    */
   public function record (int $nanoseconds): void
   {
      if ($nanoseconds < 0) {
         throw new InvalidArgumentException('Latency cannot be negative.');
      }
      if ($this->count === PHP_INT_MAX) {
         throw new OverflowException('Latency histogram observation count overflow.');
      }

      $index = null;
      if ($nanoseconds <= $this->highestTrackableNS) {
         // ? The dominant plaintext range fits the first HDR sub-bucket. Its
         //   exact index is one shift; retain the generic logarithmic mapping
         //   for the tail.
         $index = $nanoseconds < self::FIRST_UNTRACKABLE_NS
            ? $nanoseconds >> self::UNIT_MAGNITUDE
            : $this->index($nanoseconds);
         if ($this->counts[$index] === PHP_INT_MAX) {
            throw new OverflowException('Latency histogram bucket count overflow.');
         }
      }

      $this->count++;
      if ($index === null) {
         $this->overflow++;
      }
      else {
         $this->counts[$index]++; // @phpstan-ignore assign.propertyType (preallocated dense slot)
         if ($nanoseconds < self::LOWEST_DISCERNIBLE_NS) {
            $this->underflow++;
         }
      }

      if ($this->sumNS !== null) {
         $this->sumNS = $nanoseconds > PHP_INT_MAX - $this->sumNS
            ? null
            : $this->sumNS + $nanoseconds;
      }
      if ($this->minimumNS === null || $nanoseconds < $this->minimumNS) {
         $this->minimumNS = $nanoseconds;
      }
      if ($this->maximumNS === null || $nanoseconds > $this->maximumNS) {
         $this->maximumNS = $nanoseconds;
      }
   }

   /**
    * Merge another compatible distribution without averaging child quantiles.
    */
   public function merge (self $Histogram): void
   {
      if (
         $Histogram->highestTrackableNS !== $this->highestTrackableNS
         || $Histogram->countsLength !== $this->countsLength
      ) {
         throw new InvalidArgumentException('Latency histogram schemas are incompatible.');
      }
      if ($Histogram->count > PHP_INT_MAX - $this->count) {
         throw new OverflowException('Latency histogram observation count overflow.');
      }
      if ($Histogram->underflow > PHP_INT_MAX - $this->underflow) {
         throw new OverflowException('Latency histogram underflow count overflow.');
      }
      if ($Histogram->overflow > PHP_INT_MAX - $this->overflow) {
         throw new OverflowException('Latency histogram overflow count overflow.');
      }

      // ? Preserve self-merge semantics: this local array becomes the immutable
      //   source after copy-on-write begins on the first destination mutation.
      $counts = $Histogram->counts;
      foreach ($counts as $index => $count) {
         if ($count > PHP_INT_MAX - $this->counts[$index]) {
            throw new OverflowException('Latency histogram bucket count overflow.');
         }
      }

      $incomingCount = $Histogram->count;
      $incomingSumNS = $Histogram->sumNS;
      $incomingMinimumNS = $Histogram->minimumNS;
      $incomingMaximumNS = $Histogram->maximumNS;
      $incomingUnderflow = $Histogram->underflow;
      $incomingOverflow = $Histogram->overflow;

      foreach ($counts as $index => $count) {
         $this->counts[$index] += $count; // @phpstan-ignore assign.propertyType (preallocated dense slot)
      }
      $this->count += $incomingCount;
      $this->underflow += $incomingUnderflow;
      $this->overflow += $incomingOverflow;

      if (
         $this->sumNS === null
         || $incomingSumNS === null
         || $incomingSumNS > PHP_INT_MAX - $this->sumNS
      ) {
         $this->sumNS = null;
      }
      else {
         $this->sumNS += $incomingSumNS;
      }

      if (
         $incomingMinimumNS !== null
         && ($this->minimumNS === null || $incomingMinimumNS < $this->minimumNS)
      ) {
         $this->minimumNS = $incomingMinimumNS;
      }
      if (
         $incomingMaximumNS !== null
         && ($this->maximumNS === null || $incomingMaximumNS > $this->maximumNS)
      ) {
         $this->maximumNS = $incomingMaximumNS;
      }
   }

   /**
    * Select a nearest-rank percentile expressed in permille.
    *
    * `500`, `950`, `990`, and `999` select p50, p95, p99, and p99.9.
    * The returned value is the conservative upper bound of the selected HDR
    * equivalence range. A rank that falls among out-of-range observations is
    * deliberately unreportable.
    */
   public function select (int $permille): null|int
   {
      if ($permille < 1 || $permille > 1_000) {
         throw new InvalidArgumentException('Latency percentile must be between 1 and 1000 permille.');
      }
      if ($this->count === 0) {
         return null;
      }

      $rank = $this->rank($permille);
      $inRange = $this->count - $this->overflow;
      if ($rank > $inRange) {
         return null;
      }

      $cumulative = 0;
      foreach ($this->counts as $index => $count) {
         $cumulative += $count;
         if ($cumulative >= $rank) {
            return $this->decode($index);
         }
      }

      // ! Valid internal accounting makes this branch unreachable.
      return null;
   }

   /**
    * Read the bounded distribution summary.
    *
    * @return array{
    *    count:int,
    *    sum_ns:null|int,
    *    sum_overflow:bool,
    *    min_ns:null|int,
    *    p50_ns:null|int,
    *    p95_ns:null|int,
    *    p99_ns:null|int,
    *    p99_9_ns:null|int,
    *    max_ns:null|int,
    *    underflow:int,
    *    overflow:int,
    *    fidelity:bool
    * }
    */
   public function inspect (): array
   {
      $percentiles = $this->calculate();

      return [
         'count' => $this->count,
         'sum_ns' => $this->sumNS,
         'sum_overflow' => $this->sumNS === null,
         'min_ns' => $this->minimumNS,
         'p50_ns' => $percentiles['p50_ns'],
         'p95_ns' => $percentiles['p95_ns'],
         'p99_ns' => $percentiles['p99_ns'],
         'p99_9_ns' => $percentiles['p99_9_ns'],
         'max_ns' => $this->maximumNS,
         'underflow' => $this->underflow,
         'overflow' => $this->overflow,
         'fidelity' => $this->underflow === 0
            && $this->overflow === 0
            && $this->sumNS !== null,
      ];
   }

   /**
    * Export a versioned schema with only non-empty counters serialized.
    *
    * @return array{
    *    schema:string,
    *    unit:string,
    *    lowest_discernible_ns:int,
    *    highest_trackable_ns:int,
    *    significant_digits:int,
    *    counts_length:int,
    *    count:int,
    *    sum_ns:null|int,
    *    sum_overflow:bool,
    *    min_ns:null|int,
    *    max_ns:null|int,
    *    underflow:int,
    *    overflow:int,
    *    fidelity:bool,
    *    percentiles:array{p50_ns:null|int,p95_ns:null|int,p99_ns:null|int,p99_9_ns:null|int},
    *    sparse_counts:list<array{index:int,count:int}>
    * }
    */
   public function export (): array
   {
      $sparse = [];
      foreach ($this->counts as $index => $count) {
         if ($count > 0) {
            $sparse[] = ['index' => $index, 'count' => $count];
         }
      }

      $summary = $this->inspect();

      return [
         'schema' => self::SCHEMA,
         'unit' => self::UNIT,
         'lowest_discernible_ns' => self::LOWEST_DISCERNIBLE_NS,
         'highest_trackable_ns' => $this->highestTrackableNS,
         'significant_digits' => self::SIGNIFICANT_DIGITS,
         'counts_length' => $this->countsLength,
         'count' => $summary['count'],
         'sum_ns' => $summary['sum_ns'],
         'sum_overflow' => $summary['sum_overflow'],
         'min_ns' => $summary['min_ns'],
         'max_ns' => $summary['max_ns'],
         'underflow' => $summary['underflow'],
         'overflow' => $summary['overflow'],
         'fidelity' => $summary['fidelity'],
         'percentiles' => [
            'p50_ns' => $summary['p50_ns'],
            'p95_ns' => $summary['p95_ns'],
            'p99_ns' => $summary['p99_ns'],
            'p99_9_ns' => $summary['p99_9_ns'],
         ],
         'sparse_counts' => $sparse,
      ];
   }

   /**
    * Restore and validate an exported distribution.
    *
    * @param array<array-key,mixed> $data
    */
   public static function import (array $data): self
   {
      self::validate($data, [
         'schema', 'unit', 'lowest_discernible_ns', 'highest_trackable_ns',
         'significant_digits', 'counts_length', 'count', 'sum_ns',
         'sum_overflow', 'min_ns', 'max_ns', 'underflow', 'overflow',
         'fidelity', 'percentiles', 'sparse_counts',
      ]);

      $highestTrackableNS = $data['highest_trackable_ns'] ?? null;
      if (
         ($data['schema'] ?? null) !== self::SCHEMA
         || ($data['unit'] ?? null) !== self::UNIT
         || ($data['lowest_discernible_ns'] ?? null) !== self::LOWEST_DISCERNIBLE_NS
         || ($data['significant_digits'] ?? null) !== self::SIGNIFICANT_DIGITS
         || is_int($highestTrackableNS) === false
      ) {
         throw new InvalidArgumentException('Invalid latency histogram schema.');
      }

      $Histogram = new self($highestTrackableNS);
      if (($data['counts_length'] ?? null) !== $Histogram->countsLength) {
         throw new InvalidArgumentException('Invalid latency histogram count-array length.');
      }

      $count = $data['count'] ?? null;
      $sumNS = $data['sum_ns'] ?? null;
      $sumOverflow = $data['sum_overflow'] ?? null;
      $minimumNS = $data['min_ns'] ?? null;
      $maximumNS = $data['max_ns'] ?? null;
      $underflow = $data['underflow'] ?? null;
      $overflow = $data['overflow'] ?? null;
      $fidelity = $data['fidelity'] ?? null;
      $percentiles = $data['percentiles'] ?? null;
      $sparse = $data['sparse_counts'] ?? null;

      if (
         is_int($count) === false || $count < 0
         || (is_int($sumNS) === false && $sumNS !== null) || (is_int($sumNS) && $sumNS < 0)
         || is_bool($sumOverflow) === false
         || (is_int($minimumNS) === false && $minimumNS !== null) || (is_int($minimumNS) && $minimumNS < 0)
         || (is_int($maximumNS) === false && $maximumNS !== null) || (is_int($maximumNS) && $maximumNS < 0)
         || is_int($underflow) === false || $underflow < 0
         || is_int($overflow) === false || $overflow < 0
         || is_bool($fidelity) === false
         || is_array($percentiles) === false
         || is_array($sparse) === false || array_is_list($sparse) === false
      ) {
         throw new InvalidArgumentException('Invalid latency histogram counters or summary.');
      }

      self::validate($percentiles, ['p50_ns', 'p95_ns', 'p99_ns', 'p99_9_ns']);
      foreach ($percentiles as $percentile) {
         if ((is_int($percentile) === false && $percentile !== null) || (is_int($percentile) && $percentile < 0)) {
            throw new InvalidArgumentException('Invalid latency histogram percentile.');
         }
      }

      if ($underflow > $count || $overflow > $count - $underflow) {
         throw new InvalidArgumentException('Invalid latency histogram range accounting.');
      }
      $inRange = $count - $overflow;
      if ($underflow > $inRange) {
         throw new InvalidArgumentException('Invalid latency histogram underflow accounting.');
      }

      $covered = 0;
      $lastIndex = -1;
      $maximumIndex = $Histogram->index($highestTrackableNS);
      foreach ($sparse as $entry) {
         // ? Inlined shape check — exactly the keys `index` and `count`.
         //   (An equivalent self::validate() call here crashes PHPStan.)
         if (
            is_array($entry) === false
            || count($entry) !== 2
            || array_key_exists('index', $entry) === false
            || array_key_exists('count', $entry) === false
         ) {
            throw new InvalidArgumentException('Invalid sparse latency histogram entry.');
         }
         $index = $entry['index'];
         $binCount = $entry['count'];
         if (
            is_int($index) === false || $index <= $lastIndex || $index > $maximumIndex
            || is_int($binCount) === false || $binCount < 1
            || $binCount > PHP_INT_MAX - $covered
         ) {
            throw new InvalidArgumentException('Invalid sparse latency histogram ordering or count.');
         }

         $Histogram->counts[$index] = $binCount; // @phpstan-ignore assign.propertyType (validated dense slot)
         $covered += $binCount;
         $lastIndex = $index;
      }
      if ($covered !== $inRange) {
         throw new InvalidArgumentException('Sparse latency histogram counts do not close.');
      }

      $lowestIndex = $Histogram->index(self::LOWEST_DISCERNIBLE_NS);
      $mandatoryUnderflow = 0;
      $possibleUnderflow = 0;
      foreach ($sparse as $entry) {
         /** @var int $index Validated in the preceding loop. */
         $index = $entry['index'];
         /** @var int $binCount Validated in the preceding loop. */
         $binCount = $entry['count'];
         if ($index < $lowestIndex) {
            $mandatoryUnderflow += $binCount;
         }
         if ($index <= $lowestIndex) {
            $possibleUnderflow += $binCount;
         }
      }
      if ($underflow < $mandatoryUnderflow || $underflow > $possibleUnderflow) {
         throw new InvalidArgumentException('Latency histogram underflow bins are inconsistent.');
      }

      if ($count === 0) {
         if (
            $sumNS !== 0 || $sumOverflow !== false
            || $minimumNS !== null || $maximumNS !== null
            || $underflow !== 0 || $overflow !== 0
         ) {
            throw new InvalidArgumentException('Empty latency histogram metadata is inconsistent.');
         }
      }
      elseif (
         $minimumNS === null || $maximumNS === null || $minimumNS > $maximumNS
         || (($underflow > 0) !== ($minimumNS < self::LOWEST_DISCERNIBLE_NS))
         || (($overflow > 0) !== ($maximumNS > $highestTrackableNS))
         || ($inRange > 0 && $minimumNS > $highestTrackableNS)
         || ($inRange === 0 && $minimumNS <= $highestTrackableNS)
         || ($minimumNS <= $highestTrackableNS && $Histogram->counts[$Histogram->index($minimumNS)] === 0)
         || ($maximumNS <= $highestTrackableNS && $Histogram->counts[$Histogram->index($maximumNS)] === 0)
      ) {
         throw new InvalidArgumentException('Latency histogram extrema are inconsistent.');
      }

      if ($sumNS === null) {
         if (
            $sumOverflow !== true
            || $count === 0
            || $maximumNS === null
            || $maximumNS <= intdiv(PHP_INT_MAX, $count)
         ) {
            throw new InvalidArgumentException('Latency histogram sum-overflow metadata is inconsistent.');
         }
      }
      elseif (
         $sumOverflow !== false
         || ($count > 0 && (
            $maximumNS === null || $minimumNS === null
            || $sumNS < $maximumNS
            || intdiv($sumNS, $count) < $minimumNS
            || intdiv($sumNS, $count) > $maximumNS
         ))
      ) {
         throw new InvalidArgumentException('Latency histogram sum metadata is inconsistent.');
      }

      $Histogram->count = $count;
      $Histogram->sumNS = $sumNS;
      $Histogram->minimumNS = $minimumNS;
      $Histogram->maximumNS = $maximumNS;
      $Histogram->underflow = $underflow;
      $Histogram->overflow = $overflow;

      $expectedFidelity = $underflow === 0 && $overflow === 0 && $sumNS !== null;
      if ($fidelity !== $expectedFidelity || $percentiles !== $Histogram->calculate()) {
         throw new InvalidArgumentException('Latency histogram derived summary is inconsistent.');
      }

      return $Histogram;
   }

   /**
    * @return array{p50_ns:null|int,p95_ns:null|int,p99_ns:null|int,p99_9_ns:null|int}
    */
   private function calculate (): array
   {
      $values = [
         'p50_ns' => null,
         'p95_ns' => null,
         'p99_ns' => null,
         'p99_9_ns' => null,
      ];
      if ($this->count === 0) {
         return $values;
      }

      $ranks = [
         'p50_ns' => $this->rank(500),
         'p95_ns' => $this->rank(950),
         'p99_ns' => $this->rank(990),
         'p99_9_ns' => $this->rank(999),
      ];
      $inRange = $this->count - $this->overflow;
      $cumulative = 0;

      foreach ($this->counts as $index => $count) {
         if ($count === 0) {
            continue;
         }
         $cumulative += $count;

         foreach ($ranks as $name => $rank) {
            if ($values[$name] === null && $rank <= $inRange && $cumulative >= $rank) {
               $values[$name] = $this->decode($index);
            }
         }
      }

      return $values;
   }

   private function rank (int $permille): int
   {
      return intdiv($this->count, 1_000) * $permille
         + intdiv(($this->count % 1_000) * $permille + 999, 1_000);
   }

   private function index (int $nanoseconds): int
   {
      $bucket = 0;
      $threshold = self::FIRST_UNTRACKABLE_NS;
      while ($nanoseconds >= $threshold) {
         $threshold <<= 1;
         $bucket++;
      }

      $subBucket = $nanoseconds >> (self::UNIT_MAGNITUDE + $bucket);

      return (($bucket + 1) << self::HALF_BUCKET_MAGNITUDE)
         + $subBucket - self::HALF_BUCKET_COUNT;
   }

   /**
    * Decode the conservative upper bound of one counter's equivalence range.
    */
   private function decode (int $index): int
   {
      $bucket = ($index >> self::HALF_BUCKET_MAGNITUDE) - 1;
      $subBucket = ($index & (self::HALF_BUCKET_COUNT - 1)) + self::HALF_BUCKET_COUNT;
      if ($bucket < 0) {
         $subBucket -= self::HALF_BUCKET_COUNT;
         $bucket = 0;
      }

      $magnitude = self::UNIT_MAGNITUDE + $bucket;
      $lowest = $subBucket << $magnitude;

      return $lowest + (1 << $magnitude) - 1;
   }

   private static function measure (int $highestTrackableNS): int
   {
      $bucketCount = 1;
      $threshold = self::FIRST_UNTRACKABLE_NS;
      while ($threshold <= $highestTrackableNS) {
         $threshold <<= 1;
         $bucketCount++;
      }

      return ($bucketCount + 1) * self::HALF_BUCKET_COUNT;
   }

   /**
    * @param array<array-key,mixed> $data
    * @param list<string> $expected
    */
   private static function validate (array $data, array $expected): void
   {
      if (count($data) !== count($expected)) {
         throw new InvalidArgumentException('Latency histogram schema has missing or unknown fields.');
      }
      foreach ($expected as $name) {
         if (array_key_exists($name, $data) === false) {
            throw new InvalidArgumentException('Latency histogram schema has missing or unknown fields.');
         }
      }

      foreach (array_keys($data) as $name) {
         if (is_int($name)) {
            throw new InvalidArgumentException('Latency histogram schema field names must be strings.');
         }
      }
   }
}
