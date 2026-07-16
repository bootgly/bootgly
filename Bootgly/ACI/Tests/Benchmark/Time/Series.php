<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Benchmark\Time;


use const PHP_INT_MAX;
use function array_fill;
use function array_fill_keys;
use function array_is_list;
use function array_key_exists;
use function count;
use function intdiv;
use function is_array;
use function is_int;
use function is_string;
use function preg_match;
use function strcmp;
use function strlen;
use InvalidArgumentException;
use OverflowException;


/**
 * Mergeable one-second counters for one absolute monotonic measurement window.
 *
 * The window is half-open: an event at the origin belongs to bucket zero, while
 * an event at the deadline is outside the series. Absolute nanosecond values are
 * exported as decimal strings so JSON consumers never lose integer precision.
 */
final class Series
{
   public const string SCHEMA = 'bootgly.time-series.v1';
   public const string CLOCK = 'monotonic';
   public const string UNIT = 'ns';
   public const int BUCKET_NS = 1_000_000_000;
   public const int MAXIMUM_BUCKETS = 3_600;

   /** @var list<string> */
   public const array METRICS = [
      'sent',
      'responses',
      'failed',
      'censored',
      'write_failed',
      'write_censored',
      'bytes_read',
   ];

   private int $originNS;
   private int $deadlineNS;

   /** @var array<int,int> Contiguous fixed-size vector. */
   private array $sent;
   /** @var array<int,int> Contiguous fixed-size vector. */
   private array $responses;
   /** @var array<int,int> Contiguous fixed-size vector. */
   private array $failed;
   /** @var array<int,int> Contiguous fixed-size vector. */
   private array $censored;
   /** @var array<int,int> Contiguous fixed-size vector. */
   private array $writeFailed;
   /** @var array<int,int> Contiguous fixed-size vector. */
   private array $writeCensored;
   /** @var array<int,int> Contiguous fixed-size vector. */
   private array $bytesRead;

   private int $sentTotal = 0;
   private int $responsesTotal = 0;
   private int $failedTotal = 0;
   private int $censoredTotal = 0;
   private int $writeFailedTotal = 0;
   private int $writeCensoredTotal = 0;
   private int $bytesReadTotal = 0;


   public function __construct (int $originNS, int $deadlineNS)
   {
      if ($originNS < 0 || $deadlineNS <= $originNS) {
         throw new InvalidArgumentException(
            'Time-series boundaries must form a positive monotonic window.'
         );
      }

      $windowNS = $deadlineNS - $originNS;
      if ($windowNS % self::BUCKET_NS !== 0) {
         throw new InvalidArgumentException(
            'Time-series windows must contain only complete one-second buckets.'
         );
      }

      $bucketCount = intdiv($windowNS, self::BUCKET_NS);
      if ($bucketCount < 1 || $bucketCount > self::MAXIMUM_BUCKETS) {
         throw new InvalidArgumentException(
            'Time-series windows must contain between 1 and 3600 one-second buckets.'
         );
      }

      $this->originNS = $originNS;
      $this->deadlineNS = $deadlineNS;
      $empty = array_fill(0, $bucketCount, 0);
      $this->sent = $empty;
      $this->responses = $empty;
      $this->failed = $empty;
      $this->censored = $empty;
      $this->writeFailed = $empty;
      $this->writeCensored = $empty;
      $this->bytesRead = $empty;
   }

   /**
    * Add positive metric deltas at one timestamp inside the half-open window.
    *
    * @param array<string,mixed> $metrics
    */
   public function record (int $atNS, array $metrics): void
   {
      if ($atNS < $this->originNS || $atNS >= $this->deadlineNS) {
         throw new InvalidArgumentException(
            'Time-series timestamps must fall inside the half-open measurement window.'
         );
      }
      if ($metrics === []) {
         throw new InvalidArgumentException('A time-series record needs at least one metric.');
      }

      $sent = 0;
      $responses = 0;
      $failed = 0;
      $censored = 0;
      $writeFailed = 0;
      $writeCensored = 0;
      $bytesRead = 0;
      foreach ($metrics as $metric => $count) {
         if (!is_int($count) || $count < 1) {
            throw new InvalidArgumentException(
               'Time-series records require known metrics with positive integer counts.'
            );
         }
         match ($metric) {
            'sent' => $sent = $count,
            'responses' => $responses = $count,
            'failed' => $failed = $count,
            'censored' => $censored = $count,
            'write_failed' => $writeFailed = $count,
            'write_censored' => $writeCensored = $count,
            'bytes_read' => $bytesRead = $count,
            default => throw new InvalidArgumentException(
               'Time-series records require known metrics with positive integer counts.'
            ),
         };
      }

      $this->accumulate(
         $atNS,
         sent: $sent,
         responses: $responses,
         failed: $failed,
         censored: $censored,
         writeFailed: $writeFailed,
         writeCensored: $writeCensored,
         bytesRead: $bytesRead,
      );
   }

   /**
    * Update fixed counters without allocating a metric map on the hot path.
    *
    * All supplied deltas are validated before mutation, preserving the same
    * transactional behavior as record().
    */
   public function accumulate (
      int $atNS,
      int $sent = 0,
      int $responses = 0,
      int $bytesRead = 0,
      int $failed = 0,
      int $censored = 0,
      int $writeFailed = 0,
      int $writeCensored = 0,
   ): void
   {
      if ($atNS < $this->originNS || $atNS >= $this->deadlineNS) {
         throw new InvalidArgumentException(
            'Time-series timestamps must fall inside the half-open measurement window.'
         );
      }
      if (
         $sent < 0 || $responses < 0 || $failed < 0 || $censored < 0
         || $writeFailed < 0 || $writeCensored < 0 || $bytesRead < 0
         || ($sent | $responses | $failed | $censored | $writeFailed | $writeCensored | $bytesRead) === 0
      ) {
         throw new InvalidArgumentException(
            'Time-series deltas must include at least one positive integer count.'
         );
      }

      $bucket = intdiv($atNS - $this->originNS, self::BUCKET_NS);
      // ? Every bucket is non-negative and its metric total is the exact sum
      //   of all buckets. Total headroom therefore proves bucket headroom too.
      if (
         ($sent > 0 && $sent > PHP_INT_MAX - $this->sentTotal)
         || ($responses > 0 && $responses > PHP_INT_MAX - $this->responsesTotal)
         || ($failed > 0 && $failed > PHP_INT_MAX - $this->failedTotal)
         || ($censored > 0 && $censored > PHP_INT_MAX - $this->censoredTotal)
         || ($writeFailed > 0 && $writeFailed > PHP_INT_MAX - $this->writeFailedTotal)
         || ($writeCensored > 0 && $writeCensored > PHP_INT_MAX - $this->writeCensoredTotal)
         || ($bytesRead > 0 && $bytesRead > PHP_INT_MAX - $this->bytesReadTotal)
      ) {
         throw new OverflowException('Time-series counter overflow.');
      }

      if ($sent > 0) {
         $this->sent[$bucket] += $sent;
         $this->sentTotal += $sent;
      }
      if ($responses > 0) {
         $this->responses[$bucket] += $responses;
         $this->responsesTotal += $responses;
      }
      if ($failed > 0) {
         $this->failed[$bucket] += $failed;
         $this->failedTotal += $failed;
      }
      if ($censored > 0) {
         $this->censored[$bucket] += $censored;
         $this->censoredTotal += $censored;
      }
      if ($writeFailed > 0) {
         $this->writeFailed[$bucket] += $writeFailed;
         $this->writeFailedTotal += $writeFailed;
      }
      if ($writeCensored > 0) {
         $this->writeCensored[$bucket] += $writeCensored;
         $this->writeCensoredTotal += $writeCensored;
      }
      if ($bytesRead > 0) {
         $this->bytesRead[$bucket] += $bytesRead;
         $this->bytesReadTotal += $bytesRead;
      }
   }

   /** Merge counters from an identically bounded and versioned series. */
   public function merge (self $Series): void
   {
      if (
         $Series->originNS !== $this->originNS
         || $Series->deadlineNS !== $this->deadlineNS
         || count($Series->sent) !== count($this->sent)
      ) {
         throw new InvalidArgumentException('Time-series boundaries are incompatible.');
      }

      // ? Copy first so self-merge reads immutable incoming vectors after
      //   copy-on-write begins on the destination.
      $sent = $Series->sent;
      $responses = $Series->responses;
      $failed = $Series->failed;
      $censored = $Series->censored;
      $writeFailed = $Series->writeFailed;
      $writeCensored = $Series->writeCensored;
      $bytesRead = $Series->bytesRead;
      $sentTotal = $Series->sentTotal;
      $responsesTotal = $Series->responsesTotal;
      $failedTotal = $Series->failedTotal;
      $censoredTotal = $Series->censoredTotal;
      $writeFailedTotal = $Series->writeFailedTotal;
      $writeCensoredTotal = $Series->writeCensoredTotal;
      $bytesReadTotal = $Series->bytesReadTotal;

      // ? Exact non-negative totals are upper bounds for their individual
      //   buckets, so a safe merged total also proves every bucket addition.
      if (
         $sentTotal > PHP_INT_MAX - $this->sentTotal
         || $responsesTotal > PHP_INT_MAX - $this->responsesTotal
         || $failedTotal > PHP_INT_MAX - $this->failedTotal
         || $censoredTotal > PHP_INT_MAX - $this->censoredTotal
         || $writeFailedTotal > PHP_INT_MAX - $this->writeFailedTotal
         || $writeCensoredTotal > PHP_INT_MAX - $this->writeCensoredTotal
         || $bytesReadTotal > PHP_INT_MAX - $this->bytesReadTotal
      ) {
         throw new OverflowException('Time-series total overflow.');
      }

      $bucketCount = count($sent);
      for ($index = 0; $index < $bucketCount; $index++) {
         $this->sent[$index] += $sent[$index];
         $this->responses[$index] += $responses[$index];
         $this->failed[$index] += $failed[$index];
         $this->censored[$index] += $censored[$index];
         $this->writeFailed[$index] += $writeFailed[$index];
         $this->writeCensored[$index] += $writeCensored[$index];
         $this->bytesRead[$index] += $bytesRead[$index];
      }

      $this->sentTotal += $sentTotal;
      $this->responsesTotal += $responsesTotal;
      $this->failedTotal += $failedTotal;
      $this->censoredTotal += $censoredTotal;
      $this->writeFailedTotal += $writeFailedTotal;
      $this->writeCensoredTotal += $writeCensoredTotal;
      $this->bytesReadTotal += $bytesReadTotal;
   }

   /**
    * Export a deterministic JSON-safe document.
    *
    * @return array{
    *    schema:string,
    *    clock:string,
    *    unit:string,
    *    origin_ns:string,
    *    deadline_ns:string,
    *    bucket_ns:int,
    *    metrics:list<string>,
    *    buckets:list<array<string,int>>,
    *    totals:array<string,int>
    * }
    */
   public function export (): array
   {
      $buckets = [];
      $bucketCount = count($this->sent);
      for ($second = 0; $second < $bucketCount; $second++) {
         $buckets[] = [
            'second' => $second,
            'sent' => $this->sent[$second],
            'responses' => $this->responses[$second],
            'failed' => $this->failed[$second],
            'censored' => $this->censored[$second],
            'write_failed' => $this->writeFailed[$second],
            'write_censored' => $this->writeCensored[$second],
            'bytes_read' => $this->bytesRead[$second],
         ];
      }

      return [
         'schema' => self::SCHEMA,
         'clock' => self::CLOCK,
         'unit' => self::UNIT,
         'origin_ns' => (string) $this->originNS,
         'deadline_ns' => (string) $this->deadlineNS,
         'bucket_ns' => self::BUCKET_NS,
         'metrics' => self::METRICS,
         'buckets' => $buckets,
         'totals' => [
            'sent' => $this->sentTotal,
            'responses' => $this->responsesTotal,
            'failed' => $this->failedTotal,
            'censored' => $this->censoredTotal,
            'write_failed' => $this->writeFailedTotal,
            'write_censored' => $this->writeCensoredTotal,
            'bytes_read' => $this->bytesReadTotal,
         ],
      ];
   }

   /**
    * Import and revalidate an untrusted exported document.
    *
    * @param array<array-key,mixed> $data
    */
   public static function import (array $data): self
   {
      self::guard($data, [
         'schema',
         'clock',
         'unit',
         'origin_ns',
         'deadline_ns',
         'bucket_ns',
         'metrics',
         'buckets',
         'totals',
      ]);
      if (
         $data['schema'] !== self::SCHEMA
         || $data['clock'] !== self::CLOCK
         || $data['unit'] !== self::UNIT
         || $data['bucket_ns'] !== self::BUCKET_NS
         || $data['metrics'] !== self::METRICS
         || !is_array($data['buckets'])
         || !array_is_list($data['buckets'])
         || !is_array($data['totals'])
      ) {
         throw new InvalidArgumentException('Invalid time-series schema or layout.');
      }

      $originNS = self::parse($data['origin_ns']);
      $deadlineNS = self::parse($data['deadline_ns']);
      $Series = new self($originNS, $deadlineNS);
      if (count($data['buckets']) !== count($Series->sent)) {
         throw new InvalidArgumentException('Time-series bucket count does not match its window.');
      }

      self::guard($data['totals'], self::METRICS);
      $totals = array_fill_keys(self::METRICS, 0);
      $bucketKeys = ['second', ...self::METRICS];
      $sent = [];
      $responses = [];
      $failed = [];
      $censored = [];
      $writeFailed = [];
      $writeCensored = [];
      $bytesRead = [];

      foreach ($data['buckets'] as $index => $bucket) {
         if (!is_array($bucket)) {
            throw new InvalidArgumentException('Every time-series bucket must be an object.');
         }
         self::guard($bucket, $bucketKeys);
         if ($bucket['second'] !== $index) {
            throw new InvalidArgumentException('Time-series bucket indexes must be contiguous.');
         }

         foreach (self::METRICS as $metric) {
            $count = $bucket[$metric];
            if (!is_int($count) || $count < 0) {
               throw new InvalidArgumentException(
                  'Time-series bucket metrics must be non-negative integers.'
               );
            }
            if ($count > PHP_INT_MAX - $totals[$metric]) {
               throw new OverflowException("Time-series {$metric} total overflow.");
            }
            $totals[$metric] += $count;
         }
         $sent[] = $bucket['sent'];
         $responses[] = $bucket['responses'];
         $failed[] = $bucket['failed'];
         $censored[] = $bucket['censored'];
         $writeFailed[] = $bucket['write_failed'];
         $writeCensored[] = $bucket['write_censored'];
         $bytesRead[] = $bucket['bytes_read'];
      }

      foreach (self::METRICS as $metric) {
         if (!is_int($data['totals'][$metric]) || $data['totals'][$metric] < 0) {
            throw new InvalidArgumentException(
               'Time-series totals must be non-negative integers.'
            );
         }
         if ($data['totals'][$metric] !== $totals[$metric]) {
            throw new InvalidArgumentException('Time-series totals do not match its buckets.');
         }
      }

      $Series->sent = $sent;
      $Series->responses = $responses;
      $Series->failed = $failed;
      $Series->censored = $censored;
      $Series->writeFailed = $writeFailed;
      $Series->writeCensored = $writeCensored;
      $Series->bytesRead = $bytesRead;
      $Series->sentTotal = $totals['sent'];
      $Series->responsesTotal = $totals['responses'];
      $Series->failedTotal = $totals['failed'];
      $Series->censoredTotal = $totals['censored'];
      $Series->writeFailedTotal = $totals['write_failed'];
      $Series->writeCensoredTotal = $totals['write_censored'];
      $Series->bytesReadTotal = $totals['bytes_read'];

      return $Series;
   }

   /**
    * Reject missing or additional object fields without depending on key order.
    *
    * @param array<array-key,mixed> $data
    * @param list<string> $expected
    */
   private static function guard (array $data, array $expected): void
   {
      if (count($data) !== count($expected)) {
         throw new InvalidArgumentException('Time-series document fields are incomplete or unknown.');
      }
      foreach ($expected as $key) {
         if (!array_key_exists($key, $data)) {
            throw new InvalidArgumentException('Time-series document fields are incomplete or unknown.');
         }
      }
   }

   /** Parse one unsigned decimal integer without permitting cast saturation. */
   private static function parse (mixed $value): int
   {
      if (!is_string($value) || preg_match('/\A(?:0|[1-9]\d*)\z/D', $value) !== 1) {
         throw new InvalidArgumentException(
            'Time-series absolute nanoseconds must be unsigned decimal strings.'
         );
      }

      $maximum = (string) PHP_INT_MAX;
      if (
         strlen($value) > strlen($maximum)
         || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)
      ) {
         throw new InvalidArgumentException('Time-series absolute nanoseconds exceed PHP integer range.');
      }

      return (int) $value;
   }
}
