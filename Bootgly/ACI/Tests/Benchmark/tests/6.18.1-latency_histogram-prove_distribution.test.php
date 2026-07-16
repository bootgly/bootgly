<?php

use Bootgly\ACI\Tests\Benchmark\Latency\Histogram;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should record, merge and restore a bounded HDR-style latency distribution',
   test: function () {
      $Empty = new Histogram;
      $empty = $Empty->inspect();
      $emptyExport = $Empty->export();

      yield assert(
         assertion: $empty === [
            'count' => 0,
            'sum_ns' => 0,
            'sum_overflow' => false,
            'min_ns' => null,
            'p50_ns' => null,
            'p95_ns' => null,
            'p99_ns' => null,
            'p99_9_ns' => null,
            'max_ns' => null,
            'underflow' => 0,
            'overflow' => 0,
            'fidelity' => true,
         ]
         && $emptyExport['counts_length'] === 18_432
         && $emptyExport['sparse_counts'] === [],
         description: 'An empty default histogram has a fixed 18,432-counter schema',
      );

      $Histogram = new Histogram;
      for ($i = 1; $i <= 1_000; $i++) {
         $Histogram->record($i * 1_000);
      }
      $summary = $Histogram->inspect();

      yield assert(
         assertion: $summary === [
            'count' => 1_000,
            'sum_ns' => 500_500_000,
            'sum_overflow' => false,
            'min_ns' => 1_000,
            'p50_ns' => 500_223,
            'p95_ns' => 950_271,
            'p99_ns' => 990_207,
            'p99_9_ns' => 999_423,
            'max_ns' => 1_000_000,
            'underflow' => 0,
            'overflow' => 0,
            'fidelity' => true,
         ]
         && $Histogram->select(500) === 500_223
         && $Histogram->select(950) === 950_271
         && $Histogram->select(990) === 990_207
         && $Histogram->select(999) === 999_423,
         description: 'Nearest-rank p50, p95, p99 and p99.9 use conservative HDR bounds',
      );

      $Boundary = new Histogram;
      foreach ([0, 511, 512, 999, 1_000, 1_048_575, 1_048_576, 60_000_000_000] as $nanoseconds) {
         $Boundary->record($nanoseconds);
      }
      $boundary = $Boundary->export();

      yield assert(
         assertion: $boundary['sparse_counts'] === [
            ['index' => 0, 'count' => 2],
            ['index' => 1, 'count' => 3],
            ['index' => 2_047, 'count' => 1],
            ['index' => 2_048, 'count' => 1],
            ['index' => 18_172, 'count' => 1],
         ]
         && $boundary['underflow'] === 4
         && $boundary['max_ns'] === 60_000_000_000
         && $boundary['fidelity'] === false,
         description: 'Sub-bucket, bucket-transition, underflow and upper-range boundaries are stable',
      );

      $First = new Histogram;
      $Second = new Histogram;
      for ($i = 1; $i <= 1_000; $i++) {
         ($i <= 500 ? $First : $Second)->record($i * 1_000);
      }
      $First->merge($Second);

      $Self = new Histogram;
      $Self->record(10_000);
      $Self->merge($Self);

      yield assert(
         assertion: $First->export() === $Histogram->export()
         && $Self->inspect()['count'] === 2
         && $Self->inspect()['sum_ns'] === 20_000,
         description: 'Exact-bin merging equals the union and preserves self-merge semantics',
      );

      $export = $Histogram->export();
      $JSON = json_encode($export, JSON_THROW_ON_ERROR);
      /** @var array<array-key,mixed> $decoded */
      $decoded = json_decode($JSON, true, 512, JSON_THROW_ON_ERROR);
      $RoundTrip = Histogram::import($decoded);

      yield assert(
         assertion: $RoundTrip->export() === $export,
         description: 'A JSON export/import round trip preserves every counter and derived percentile',
      );

      $Overflow = new Histogram(1_000_000);
      $Overflow->record(1_000);
      $Overflow->record(1_000_001);
      $overflow = $Overflow->inspect();

      yield assert(
         assertion: $overflow['count'] === 2
         && $overflow['overflow'] === 1
         && $overflow['max_ns'] === 1_000_001
         && $overflow['p50_ns'] === 1_023
         && $overflow['p95_ns'] === null
         && $overflow['fidelity'] === false
         && Histogram::import($Overflow->export())->export() === $Overflow->export(),
         description: 'Ranks inside overflow observations fail closed without clamping the exact maximum',
      );

      $SumOverflow = new Histogram;
      $SumOverflow->record(PHP_INT_MAX);
      $SumOverflow->record(1);
      $sumOverflow = $SumOverflow->inspect();

      yield assert(
         assertion: $sumOverflow['count'] === 2
         && $sumOverflow['sum_ns'] === null
         && $sumOverflow['sum_overflow'] === true
         && $sumOverflow['underflow'] === 1
         && $sumOverflow['overflow'] === 1
         && Histogram::import($SumOverflow->export())->export() === $SumOverflow->export(),
         description: 'Integer sum overflow is explicit while the distribution remains mergeable',
      );

      $invalid = [];

      $unknown = $export;
      $unknown['unknown'] = true;
      $invalid[] = $unknown;

      $schema = $export;
      $schema['schema'] = 'unknown';
      $invalid[] = $schema;

      $percentile = $export;
      $percentile['percentiles']['p50_ns']++;
      $invalid[] = $percentile;

      $ordering = $export;
      $ordering['sparse_counts'] = array_reverse($ordering['sparse_counts']);
      $invalid[] = $ordering;

      $closure = $export;
      $closure['count']++;
      $invalid[] = $closure;

      $underflow = $Boundary->export();
      $underflow['underflow'] = 1;
      $invalid[] = $underflow;

      $extrema = $export;
      $extrema['min_ns'] = 1_500;
      $invalid[] = $extrema;

      $rejected = 0;
      foreach ($invalid as $data) {
         try {
            Histogram::import($data);
         }
         catch (InvalidArgumentException) {
            $rejected++;
         }
      }

      $Different = new Histogram(1_000_000);
      $mergeRejected = false;
      try {
         $Histogram->merge($Different);
      }
      catch (InvalidArgumentException) {
         $mergeRejected = true;
      }

      $argumentsRejected = 0;
      foreach ([-1, 0, 1_001] as $value) {
         try {
            if ($value === -1) {
               $Empty->record($value);
            }
            else {
               $Empty->select($value);
            }
         }
         catch (InvalidArgumentException) {
            $argumentsRejected++;
         }
      }

      yield assert(
         assertion: $rejected === count($invalid)
         && $mergeRejected
         && $argumentsRejected === 3,
         description: 'Malformed schemas, incompatible merges and invalid observations are rejected',
      );

      $saturated = $emptyExport;
      $saturated['count'] = PHP_INT_MAX;
      $saturated['sum_ns'] = null;
      $saturated['sum_overflow'] = true;
      $saturated['min_ns'] = 1_000;
      $saturated['max_ns'] = 1_000;
      $saturated['fidelity'] = false;
      $saturated['percentiles'] = [
         'p50_ns' => 1_023,
         'p95_ns' => 1_023,
         'p99_ns' => 1_023,
         'p99_9_ns' => 1_023,
      ];
      $saturated['sparse_counts'] = [['index' => 1, 'count' => PHP_INT_MAX]];
      $Saturated = Histogram::import($saturated);
      $countOverflowRejected = false;
      try {
         $Saturated->record(1_000);
      }
      catch (OverflowException) {
         $countOverflowRejected = true;
      }

      yield assert(
         assertion: $countOverflowRejected
         && $Saturated->inspect()['count'] === PHP_INT_MAX,
         description: 'Observation counter overflow is rejected before mutating the distribution',
      );
   },
);
