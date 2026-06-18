<?php

use Bootgly\ACI\Observability\Data\Snapshot;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Snapshot::import rebuilds metrics + timestamp and degrades on bad input',
   test: function () {
      $data = [
         'timestamp' => 1234.5,
         'metrics' => [
            'http_requests_total' => ['type' => 'counter', 'help' => 'reqs', 'series' => [
               ['labels' => ['method' => 'GET'], 'value' => 7.0],
            ]],
         ],
      ];

      $Snapshot = Snapshot::import($data);

      yield assert(
         assertion: $Snapshot->timestamp === 1234.5,
         description: 'timestamp restored'
      );
      yield assert(
         assertion: $Snapshot->metrics['http_requests_total']['series'][0]['value'] === 7.0,
         description: 'metrics restored'
      );

      // # Malformed input degrades gracefully
      $Empty = Snapshot::import(['garbage' => true]);
      yield assert(
         assertion: $Empty->metrics === [],
         description: 'missing metrics yields an empty snapshot'
      );
   }
);
