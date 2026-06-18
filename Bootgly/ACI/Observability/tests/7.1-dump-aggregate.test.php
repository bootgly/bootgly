<?php

use Bootgly\ACI\Observability;
use Bootgly\ACI\Observability\Exporters\JSON;
use Bootgly\ACI\Observability\Metrics\Counter;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'dump() writes per-worker JSON and aggregate() merges them, skipping stale files',
   test: function () {
      $dir = sys_get_temp_dir() . '/bootgly-obs-' . uniqid();

      // # Worker 1 snapshot: GET +5
      $O1 = new Observability(collectors: false);
      $C1 = new Counter(name: 'http_requests_total', labels: ['method' => 'GET']);
      $C1->increment(by: 5);
      $O1->Metrics->push($C1);
      $ok1 = $O1->dump(new JSON, "$dir/worker-1.json");

      // # Worker 2 snapshot: GET +3, POST +2
      $O2 = new Observability(collectors: false);
      $GET = new Counter(name: 'http_requests_total', labels: ['method' => 'GET']);
      $GET->increment(by: 3);
      $POST = new Counter(name: 'http_requests_total', labels: ['method' => 'POST']);
      $POST->increment(by: 2);
      $O2->Metrics->push($GET)->push($POST);
      $ok2 = $O2->dump(new JSON, "$dir/worker-2.json");

      yield assert(
         assertion: $ok1 && $ok2 && is_file("$dir/worker-1.json") && is_file("$dir/worker-2.json"),
         description: 'dump() writes one file per worker'
      );

      // # Merge across workers
      $Cluster = Observability::aggregate("$dir/worker-*.json");
      $get = null;
      $post = null;
      foreach ($Cluster->metrics['http_requests_total']['series'] as $Series) {
         if (($Series['labels']['method'] ?? null) === 'GET') $get = $Series['value'];
         if (($Series['labels']['method'] ?? null) === 'POST') $post = $Series['value'];
      }
      yield assert(
         assertion: $get === 8.0 && $post === 2.0,
         description: 'aggregate() sums matching series (GET 5+3=8) and unions POST=2'
      );

      // # Stale skip: age worker-1 beyond maxAge
      touch("$dir/worker-1.json", time() - 100);
      $Fresh = Observability::aggregate("$dir/worker-*.json", maxAge: 30.0);
      $freshGet = null;
      foreach ($Fresh->metrics['http_requests_total']['series'] as $Series) {
         if (($Series['labels']['method'] ?? null) === 'GET') $freshGet = $Series['value'];
      }
      yield assert(
         assertion: $freshGet === 3.0,
         description: 'aggregate(maxAge) skips the stale worker-1 file (only worker-2 GET=3 remains)'
      );

      // @ Cleanup
      @unlink("$dir/worker-1.json");
      @unlink("$dir/worker-2.json");
      @rmdir($dir);

      yield assert(
         assertion: is_dir($dir) === false,
         description: 'temp directory cleaned up'
      );
   }
);
