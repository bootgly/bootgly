<?php

use Bootgly\ACI\Observability\Data\Snapshot;
use Bootgly\ACI\Observability\Exporters\JSON;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'JSON exporter emits stable {timestamp, metrics} output and round-trips via import',
   test: function () {
      $Snapshot = new Snapshot([
         'http_requests_total' => ['type' => 'counter', 'help' => 'Total requests.', 'series' => [
            ['labels' => ['method' => 'GET'], 'value' => 5.5],
         ]],
      ]);
      $Snapshot->timestamp = 1700000000.5;

      $json = (new JSON)->export($Snapshot);

      $expected = '{"timestamp":1700000000.5,"metrics":{"http_requests_total":'
         . '{"type":"counter","help":"Total requests.","series":[{"labels":{"method":"GET"},"value":5.5}]}}}'
         . PHP_EOL;

      yield assert(
         assertion: $json === $expected,
         description: 'golden JSON output matches byte-for-byte'
      );

      // # Round-trip back through Snapshot::import
      $decoded = json_decode($json, true);
      $Back = Snapshot::import($decoded);

      yield assert(
         assertion: $Back->timestamp === 1700000000.5
            && $Back->metrics['http_requests_total']['series'][0]['value'] === 5.5,
         description: 'export → import round-trips losslessly'
      );
   }
);
