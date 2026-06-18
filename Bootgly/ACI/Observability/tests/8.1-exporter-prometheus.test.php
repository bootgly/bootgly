<?php


use function assert;
use function str_contains;

use Bootgly\ACI\Observability\Data\Snapshot;
use Bootgly\ACI\Observability\Exporters\Prometheus;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Prometheus exporter emits text exposition: HELP/TYPE + samples + histogram buckets',
   test: function () {
      $Snapshot = new Snapshot([
         'http_requests_total' => ['type' => 'counter', 'help' => 'Total requests.', 'series' => [
            ['labels' => ['method' => 'GET'], 'value' => 5.0],
            ['labels' => ['method' => 'POST'], 'value' => 2.0],
         ]],
         'workers_active' => ['type' => 'gauge', 'help' => 'Active workers.', 'series' => [
            ['labels' => [], 'value' => 8.0],
         ]],
         'req_duration_seconds' => ['type' => 'histogram', 'help' => 'Latency.', 'series' => [
            ['labels' => [], 'buckets' => ['0.1' => 1, '0.5' => 2, '1' => 3, '+Inf' => 4], 'sum' => 6.05, 'count' => 4],
         ]],
      ]);

      $text = (new Prometheus)->export($Snapshot);

      $expected = <<<PROM
      # HELP http_requests_total Total requests.
      # TYPE http_requests_total counter
      http_requests_total{method="GET"} 5
      http_requests_total{method="POST"} 2
      # HELP workers_active Active workers.
      # TYPE workers_active gauge
      workers_active 8
      # HELP req_duration_seconds Latency.
      # TYPE req_duration_seconds histogram
      req_duration_seconds_bucket{le="0.1"} 1
      req_duration_seconds_bucket{le="0.5"} 2
      req_duration_seconds_bucket{le="1"} 3
      req_duration_seconds_bucket{le="+Inf"} 4
      req_duration_seconds_sum 6.05
      req_duration_seconds_count 4

      PROM;

      yield assert(
         assertion: $text === $expected,
         description: 'golden Prometheus exposition matches byte-for-byte'
      );

      // # Namespace prefix
      $prefixed = (new Prometheus(namespace: 'bootgly'))->export(new Snapshot([
         'up' => ['type' => 'gauge', 'help' => '', 'series' => [['labels' => [], 'value' => 1.0]]],
      ]));
      yield assert(
         assertion: str_contains($prefixed, "# TYPE bootgly_up gauge") && str_contains($prefixed, "bootgly_up 1"),
         description: 'namespace prefixes the metric name'
      );

      // # Label-value escaping
      $escaped = (new Prometheus)->export(new Snapshot([
         'm' => ['type' => 'counter', 'help' => '', 'series' => [
            ['labels' => ['path' => 'a"b\\c'], 'value' => 1.0],
         ]],
      ]));
      yield assert(
         assertion: str_contains($escaped, 'path="a\\"b\\\\c"'),
         description: 'label values escape quotes and backslashes'
      );
   }
);
