<?php

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark\Result;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should strip raw telemetry and keep every aggregate',
   test: new Assertions(Case: function (): Generator
   {
      // ! One fully-populated server result
      $aggregates = [
         'time' => '0.5',
         'memory' => 2048,
         'rps' => 104532.0,
         'latency' => '1.2ms',
         'transfer' => '24.1MB/s',
         'scheduled' => 104538,
         'sent' => 104535,
         'responses' => 104532,
         'informational' => 7,
         'outstanding' => 0,
         'failed' => 1,
         'writeFailed' => 1,
         'connectionFailed' => 0,
         'partialWrites' => 2,
         'accounting' => true,
         'statuses' => [200 => 104530, 503 => 2],
         'failures' => ['connection_closed' => 1],
         'writeFailures' => ['measurement_ended' => 1],
         'censored' => 2,
         'writeCensored' => 2,
         'censors' => ['measurement_ended' => 2],
         'writeCensors' => ['measurement_ended' => 2],
         'latencySummary' => [
            'count' => 104532,
            'sum_ns' => 125438400000,
            'sum_overflow' => false,
            'min_ns' => 8500,
            'p50_ns' => 12000,
            'p95_ns' => 18000,
            'p99_ns' => 24000,
            'p99_9_ns' => 31000,
            'max_ns' => 47000,
            'underflow' => 0,
            'overflow' => 0,
            'fidelity' => true,
         ],
      ];
      $latencyHistogram = [
         'schema' => 'bootgly.latency-hdr.v1',
         'sparse_counts' => [['index' => 12, 'count' => 104532]],
      ];
      $timeSeries = [
         'schema' => 'bootgly.time-series.v1',
         'buckets' => [['second' => 0, 'responses' => 104532]],
      ];
      $Result = new Result(
         ...$aggregates,
         latencyHistogram: $latencyHistogram,
         timeSeries: $timeSeries,
      );

      // @
      $Stripped = $Result->strip();

      // ! Every public property, read back by name
      $Read = static function (Result $Result): array {
         $values = [];
         foreach (new ReflectionClass(Result::class)->getProperties(ReflectionProperty::IS_PUBLIC) as $Property) {
            $values[$Property->getName()] = $Property->getValue($Result);
         }

         return $values;
      };
      $stripped = $Read($Stripped);

      yield new Assertion(
         description: 'The copy keeps every aggregate the report and the JSON document read',
         fallback: 'Result::strip() dropped or changed an aggregate!'
      )
         ->expect(
            array_diff_key($stripped, ['latencyHistogram' => true, 'timeSeries' => true]),
            Op::Identical,
            $aggregates
         )
         ->assert();

      yield new Assertion(
         description: 'The copy drops the raw latency histogram and one-second series',
         fallback: 'Result::strip() retained raw telemetry!'
      )
         ->expect(
            $Stripped !== $Result
               && $stripped['latencyHistogram'] === null
               && $stripped['timeSeries'] === null,
            Op::Identical,
            true
         )
         ->assert();

      yield new Assertion(
         description: 'The source result is left untouched for its telemetry sidecar',
         fallback: 'Result::strip() mutated the source result!'
      )
         ->expect(
            $Result->latencyHistogram === $latencyHistogram
               && $Result->timeSeries === $timeSeries
               && $Result->latencySummary === $aggregates['latencySummary'],
            Op::Identical,
            true
         )
         ->assert();

      yield new Assertion(
         description: 'A result without raw telemetry strips to an equal copy',
         fallback: 'Result::strip() changed a result that had no raw telemetry!'
      )
         ->expect(
            $Read(new Result(time: '0.001', memory: 64)->strip()),
            Op::Identical,
            $Read(new Result(time: '0.001', memory: 64))
         )
         ->assert();
   })
);
