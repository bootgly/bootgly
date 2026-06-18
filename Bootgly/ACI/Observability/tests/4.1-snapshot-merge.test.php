<?php

use Bootgly\ACI\Observability\Data\Snapshot;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Snapshot::merge sums matching series, unions distinct labels, and adds histograms',
   test: function () {
      // # Counter: same labels sum, distinct labels union, new names copy in
      $A = new Snapshot([
         'http_requests_total' => ['type' => 'counter', 'help' => '', 'series' => [
            ['labels' => ['method' => 'GET'], 'value' => 5.0],
         ]],
      ]);
      $B = new Snapshot([
         'http_requests_total' => ['type' => 'counter', 'help' => '', 'series' => [
            ['labels' => ['method' => 'GET'], 'value' => 3.0],
            ['labels' => ['method' => 'POST'], 'value' => 2.0],
         ]],
         'workers_active' => ['type' => 'gauge', 'help' => '', 'series' => [
            ['labels' => [], 'value' => 9.0],
         ]],
      ]);

      $A->merge($B);

      yield assert(
         assertion: $A->metrics['http_requests_total']['series'][0]['value'] === 8.0,
         description: 'matching GET series sum (5 + 3)'
      );
      yield assert(
         assertion: count($A->metrics['http_requests_total']['series']) === 2,
         description: 'distinct POST series is unioned'
      );
      yield assert(
         assertion: isset($A->metrics['workers_active']),
         description: 'new metric name is copied in'
      );

      // # Histogram: buckets + sum + count add
      $H1 = new Snapshot(['h' => ['type' => 'histogram', 'help' => '', 'series' => [
         ['labels' => [], 'buckets' => ['0.5' => 1, '+Inf' => 2], 'sum' => 0.6, 'count' => 2],
      ]]]);
      $H2 = new Snapshot(['h' => ['type' => 'histogram', 'help' => '', 'series' => [
         ['labels' => [], 'buckets' => ['0.5' => 2, '+Inf' => 3], 'sum' => 0.9, 'count' => 3],
      ]]]);
      $H1->merge($H2);

      $series = $H1->metrics['h']['series'][0];
      yield assert(
         assertion: $series['buckets']['0.5'] === 3 && $series['buckets']['+Inf'] === 5,
         description: 'histogram buckets add bucket-wise'
      );
      yield assert(
         assertion: $series['count'] === 5 && abs($series['sum'] - 1.5) < 1e-9,
         description: 'histogram sum and count add'
      );
   }
);
