<?php

use Bootgly\ACI\Observability\Data\Snapshot;
use Bootgly\ACI\Observability\Exporters\OTLP;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'OTLP exporter emits resourceMetrics with sum/gauge/histogram and int64-as-string fields',
   test: function () {
      $Snapshot = new Snapshot([
         'http_requests_total' => ['type' => 'counter', 'help' => 'Total requests.', 'series' => [
            ['labels' => ['method' => 'GET'], 'value' => 5.0],
         ]],
         'workers_active' => ['type' => 'gauge', 'help' => 'Active workers.', 'series' => [
            ['labels' => [], 'value' => 8.0],
         ]],
         'req_duration_seconds' => ['type' => 'histogram', 'help' => 'Latency.', 'series' => [
            ['labels' => [], 'buckets' => ['0.1' => 1, '0.5' => 2, '1' => 3, '+Inf' => 4], 'sum' => 6.05, 'count' => 4],
         ]],
      ]);
      $Snapshot->timestamp = 1700000000.5;

      $raw = (new OTLP(service: 'demo'))->export($Snapshot);
      $doc = json_decode($raw, true);

      $scope = $doc['resourceMetrics'][0]['scopeMetrics'][0];
      $metrics = $scope['metrics'];

      yield assert(
         assertion: $doc['resourceMetrics'][0]['resource']['attributes'][0]['value']['stringValue'] === 'demo'
            && $scope['scope']['name'] === 'bootgly.observability',
         description: 'resource service.name + instrumentation scope are set'
      );

      // # Counter → monotonic cumulative sum
      $sum = $metrics[0]['sum'];
      yield assert(
         assertion: $sum['isMonotonic'] === true && $sum['aggregationTemporality'] === 2
            && $sum['dataPoints'][0]['asDouble'] === 5.0
            && $sum['dataPoints'][0]['attributes'][0]['key'] === 'method'
            && $sum['dataPoints'][0]['attributes'][0]['value']['stringValue'] === 'GET',
         description: 'counter maps to a monotonic cumulative sum with attributes'
      );

      // # int64 fields encoded as strings
      yield assert(
         assertion: $sum['dataPoints'][0]['timeUnixNano'] === '1700000000500000000',
         description: 'timeUnixNano is an int64 string (precision-stable)'
      );

      // # Gauge
      yield assert(
         assertion: $metrics[1]['gauge']['dataPoints'][0]['asDouble'] === 8.0,
         description: 'gauge maps to a gauge data point'
      );

      // # Histogram → de-cumulated bucketCounts + explicit bounds
      $hist = $metrics[2]['histogram']['dataPoints'][0];
      yield assert(
         assertion: $hist['bucketCounts'] === ['1', '1', '1', '1']
            && $hist['explicitBounds'] === [0.1, 0.5, 1.0]
            && $hist['count'] === '4'
            && $hist['sum'] === 6.05,
         description: 'histogram de-cumulates buckets; count is an int64 string'
      );

      yield assert(
         assertion: str_contains($raw, '"timeUnixNano":"1700000000500000000"')
            && str_contains($raw, '"bucketCounts":["1","1","1","1"]'),
         description: 'raw JSON carries string int64 fields'
      );
   }
);
