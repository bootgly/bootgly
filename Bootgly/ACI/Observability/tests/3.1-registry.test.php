<?php

use Bootgly\ACI\Observability;
use Bootgly\ACI\Observability\Metrics\Counter;
use Bootgly\ACI\Observability\Metrics\Gauge;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Registry groups instruments by name and the facade gathers a snapshot',
   test: function () {
      $O = new Observability();

      $GET = new Counter(name: 'http_requests_total', labels: ['method' => 'GET']);
      $GET->increment(by: 3);
      $POST = new Counter(name: 'http_requests_total', labels: ['method' => 'POST']);
      $POST->increment();
      $O->Metrics->push($GET)->push($POST);

      $Gauge = new Gauge(name: 'workers_active');
      $Gauge->set(8.0);
      $O->Metrics->push($Gauge);

      $Snapshot = $O->gather();

      yield assert(
         assertion: isset($Snapshot->metrics['http_requests_total']),
         description: 'metric present by name'
      );
      yield assert(
         assertion: count($Snapshot->metrics['http_requests_total']['series']) === 2,
         description: 'same-named instruments group into multiple series'
      );
      yield assert(
         assertion: $Snapshot->metrics['http_requests_total']['type'] === 'counter',
         description: 'series carry their metric type'
      );
      yield assert(
         assertion: $Snapshot->metrics['workers_active']['series'][0]['value'] === 8.0,
         description: 'gauge value captured in the snapshot'
      );
      yield assert(
         assertion: $Snapshot->timestamp > 0,
         description: 'snapshot is timestamped'
      );
   }
);
