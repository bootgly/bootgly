<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Observability\Exporters;


use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PHP_EOL;
use function is_array;
use function is_float;
use function is_int;
use function is_scalar;
use function is_string;
use function json_encode;
use JsonException;

use Bootgly\ACI\Observability\Data\Snapshot;
use Bootgly\ACI\Observability\Exporter;


class OTLP implements Exporter
{
   // OTLP AggregationTemporality: 2 = CUMULATIVE.
   private const int CUMULATIVE = 2;

   // * Config
   public string $service;
   public string $scope;


   /**
    * Build an OTLP/HTTP (JSON) exporter.
    *
    * @param string $service Value for the `service.name` resource attribute.
    * @param string $scope Instrumentation scope name.
    */
   public function __construct (string $service = 'bootgly', string $scope = 'bootgly.observability')
   {
      // * Config
      $this->service = $service;
      $this->scope = $scope;
   }

   /**
    * Encode a snapshot as an OTLP/HTTP metrics request (JSON, no protobuf dependency).
    *
    * Counters map to `sum` (monotonic, cumulative), gauges to `gauge`, histograms to `histogram`
    * with de-cumulated `bucketCounts` + `explicitBounds`. int64 fields (`timeUnixNano`, `count`,
    * `bucketCounts`) are encoded as strings per the OTLP/JSON mapping.
    *
    * @param Snapshot $Snapshot The snapshot to encode.
    * @return string A single JSON document (`{resourceMetrics: […]}`).
    */
   public function export (Snapshot $Snapshot): string
   {
      $nano = $this->stamp($Snapshot->timestamp);

      // @ Build one OTLP metric per snapshot metric
      $metrics = [];
      foreach ($Snapshot->metrics as $name => $metric) {
         $type = $metric['type'];
         $base = ['name' => $name, 'description' => $metric['help']];

         // @ Data points per series
         $points = [];
         foreach ($metric['series'] as $Series) {
            $labels = is_array($Series['labels'] ?? null) ? $Series['labels'] : [];
            $attributes = $this->map($labels);

            if ($type === 'histogram') {
               $points[] = $this->bucketize($Series, $attributes, $nano);
            }
            else {
               $points[] = [
                  'attributes'        => $attributes,
                  'startTimeUnixNano' => $nano,
                  'timeUnixNano'      => $nano,
                  'asDouble'          => $this->normalize($Series['value'] ?? 0),
               ];
            }
         }

         // ?: Wrap data points in the kind-specific OTLP container
         $metrics[] = match ($type) {
            'counter'   => $base + ['sum' => [
               'dataPoints'             => $points,
               'aggregationTemporality' => self::CUMULATIVE,
               'isMonotonic'            => true,
            ]],
            'histogram' => $base + ['histogram' => [
               'dataPoints'             => $points,
               'aggregationTemporality' => self::CUMULATIVE,
            ]],
            default     => $base + ['gauge' => ['dataPoints' => $points]],
         };
      }

      // @ Wrap in the OTLP resource/scope envelope
      $document = [
         'resourceMetrics' => [[
            'resource' => ['attributes' => [
               ['key' => 'service.name', 'value' => ['stringValue' => $this->service]],
            ]],
            'scopeMetrics' => [[
               'scope'   => ['name' => $this->scope],
               'metrics' => $metrics,
            ]],
         ]],
      ];

      // @ Encode (preserve float fractions; int64 fields are already strings)
      //   Fail loud — return '' on an encoding error rather than a misleading partial/`{}` body.
      try {
         $json = json_encode(
            $document,
            JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
         );
      }
      catch (JsonException) {
         return '';
      }

      // :
      return $json . PHP_EOL;
   }

   /**
    * Build a histogram data point, de-cumulating the snapshot's cumulative `le` buckets.
    *
    * @param array<string, mixed> $Series The histogram series (`buckets`, `sum`, `count`).
    * @param list<array{key: string, value: array{stringValue: string}}> $attributes OTLP attributes.
    * @param string $nano Timestamp in unix nanoseconds (string).
    * @return array<string, mixed>
    */
   private function bucketize (array $Series, array $attributes, string $nano): array
   {
      $buckets = is_array($Series['buckets'] ?? null) ? $Series['buckets'] : [];

      $bounds = [];
      $counts = [];
      $previous = 0;
      $total = 0;

      foreach ($buckets as $le => $cumulative) {
         $count = (int) $this->normalize($cumulative);

         // # The +Inf bucket carries the running total, not a finite bound
         if ((string) $le === '+Inf') {
            $total = $count;
            continue;
         }

         $bounds[] = (float) $le;
         $counts[] = (string) ($count - $previous);
         $previous = $count;
      }
      // @ +Inf bucket = total minus the last finite cumulative count
      $counts[] = (string) ($total - $previous);

      // :
      return [
         'attributes'        => $attributes,
         'startTimeUnixNano' => $nano,
         'timeUnixNano'      => $nano,
         'count'             => (string) $total,
         'sum'               => $this->normalize($Series['sum'] ?? 0),
         'bucketCounts'      => $counts,
         'explicitBounds'    => $bounds,
      ];
   }

   /**
    * Map a label set to OTLP key/value attributes (string values).
    *
    * @param array<array-key, mixed> $labels
    * @return list<array{key: string, value: array{stringValue: string}}>
    */
   private function map (array $labels): array
   {
      $attributes = [];

      foreach ($labels as $key => $value) {
         if (is_string($key) === false) {
            continue;
         }
         $string = is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
         $attributes[] = ['key' => $key, 'value' => ['stringValue' => $string]];
      }

      // :
      return $attributes;
   }

   /**
    * Build a unix-nanosecond timestamp string from a float-seconds timestamp (precision-stable).
    *
    * @param float $timestamp Seconds since the epoch.
    * @return string Nanoseconds as a string (OTLP int64 mapping).
    */
   private function stamp (float $timestamp): string
   {
      $seconds = (int) $timestamp;
      $nanoseconds = (int) (($timestamp - $seconds) * 1_000_000_000);

      // :
      return (string) ($seconds * 1_000_000_000 + $nanoseconds);
   }

   /**
    * Coerce a decoded value to a float (0.0 when not numeric).
    *
    * @param mixed $value
    * @return float
    */
   private function normalize (mixed $value): float
   {
      // :
      return is_int($value) || is_float($value) ? (float) $value : 0.0;
   }
}
